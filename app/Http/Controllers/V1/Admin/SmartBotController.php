<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class SmartBotController extends Controller
{
    private $platforms = ['android', 'ios', 'windows'];

    /** پسوندهایی که قطعاً «فایل دانلودی» هستند */
    private $fileExts = ['apk', 'exe', 'zip', 'rar', '7z', 'dmg', 'pkg', 'msi', 'ipa', 'mp4', 'mkv', 'mov', 'avi', 'pdf'];

    private function storageDir(): string
    {
        return public_path('bot_files');
    }

    /** خواندن همه‌ی متون و لینک‌های راهنما */
    public function get(Request $request)
    {
        $data = ['text_help' => BotText::get('text_help', '')];
        foreach ($this->platforms as $p) {
            $data['help_' . $p . '_text']  = BotText::get('help_' . $p . '_text', '');
            $data['help_' . $p . '_image'] = BotText::get('help_' . $p . '_image', '');
            $links = json_decode(BotText::get('help_' . $p . '_links', '[]'), true);
            $data['help_' . $p . '_links'] = is_array($links) ? array_values($links) : [];
        }
        $data['notify_all_admins'] = \App\Models\BotSetting::getBool('notify_all_admins', false);
        $data['backup_all_admins'] = \App\Models\BotSetting::getBool('backup_all_admins', false);
        return response(['data' => $data]);
    }

    /** ذخیره‌ی همه‌ی بخش‌ها */
    public function save(Request $request)
    {
        $input = $request->all();
        BotText::set('text_help', (string) ($input['text_help'] ?? ''));

        foreach ($this->platforms as $p) {
            BotText::set('help_' . $p . '_text', (string) ($input['help_' . $p . '_text'] ?? ''));
            BotText::set('help_' . $p . '_image', (string) ($input['help_' . $p . '_image'] ?? ''));

            $newLinks = $input['help_' . $p . '_links'] ?? [];
            if (!is_array($newLinks)) $newLinks = [];

            $oldLinks = json_decode(BotText::get('help_' . $p . '_links', '[]'), true);
            if (!is_array($oldLinks)) $oldLinks = [];

            $processed = $this->processLinks($newLinks, $oldLinks);
            BotText::set('help_' . $p . '_links', json_encode(array_values($processed), JSON_UNESCAPED_UNICODE));
        }
        \App\Models\BotSetting::set('notify_all_admins', !empty($input['notify_all_admins']) ? '1' : '0');
        \App\Models\BotSetting::set('backup_all_admins', !empty($input['backup_all_admins']) ? '1' : '0');
        return response(['data' => true]);
    }

    /**
     * پردازش لینک‌ها: تشخیص نوع، دانلود فایل‌های جدید، نگه‌داشتن فایل‌های موجود،
     * و حذف فایل‌های لینک‌هایی که دیگر نیستند.
     */
    private function processLinks(array $newLinks, array $oldLinks): array
    {
        $dir = $this->storageDir();
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // نگاشت لینک‌های قبلی بر اساس url (برای جلوگیری از دانلود مجدد)
        $oldByUrl = [];
        foreach ($oldLinks as $ol) {
            if (!empty($ol['url'])) $oldByUrl[$ol['url']] = $ol;
        }

        $result    = [];
        $keptFiles = [];

        foreach ($newLinks as $link) {
            $url   = trim($link['url'] ?? '');
            $label = trim($link['label'] ?? '');
            $type  = $link['type'] ?? 'auto';
            if ($url === '') continue;

            // تشخیص خودکار در صورت انتخاب «auto»
            if ($type === 'auto' || $type === '') {
                $type = $this->detectType($url);
            }

            $file = '';
            if ($type === 'file') {
                $prev = $oldByUrl[$url] ?? null;
                if ($prev && ($prev['type'] ?? '') === 'file' && !empty($prev['file'])
                    && File::exists(public_path(ltrim($prev['file'], '/')))) {
                    // همین url قبلاً دانلود شده و فایلش هست → دوباره دانلود نکن
                    $file = $prev['file'];
                } else {
                    $file = $this->downloadFile($url);
                    if ($file === '') {
                        // دانلود ناموفق → به لینک ساده تنزل بده
                        $type = 'url';
                    }
                }
            }
            if ($file !== '') $keptFiles[] = $file;

            $result[] = [
                'label' => $label,
                'type'  => $type,
                'url'   => $url,
                'file'  => $file,
            ];
        }

        // حذف فایل‌های قدیمی که در لیست جدید استفاده نشده‌اند
        foreach ($oldLinks as $ol) {
            $of = $ol['file'] ?? '';
            if ($of !== '' && !in_array($of, $keptFiles, true)) {
                $path = public_path(ltrim($of, '/'));
                if (File::exists($path)) @File::delete($path);
            }
        }

        return $result;
    }

    /** تشخیص نوع لینک از روی پسوند و سپس Content-Type */
    private function detectType(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, $this->fileExts, true)) {
            return 'file';
        }

        $ctype = $this->remoteContentType($url);
        if ($ctype !== '') {
            if (stripos($ctype, 'text/html') !== false) return 'url';
            if (stripos($ctype, 'application/') !== false
                || stripos($ctype, 'video/') !== false
                || stripos($ctype, 'octet-stream') !== false) {
                return 'file';
            }
        }
        return 'url';
    }

    /** گرفتن Content-Type با درخواست HEAD */
    private function remoteContentType(string $url): string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
            ]);
            curl_exec($ch);
            $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            return is_string($ct) ? $ct : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** دانلود فایل و برگرداندن مسیر نسبی نسبت به public (یا رشته‌ی خالی در صورت خطا) */
    private function downloadFile(string $url): string
    {
        try {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $name = 'bf_' . date('Ymd_His') . '_' . substr(md5($url . microtime()), 0, 8);
            if ($ext !== '') $name .= '.' . $ext;
            $dest = $this->storageDir() . '/' . $name;

            $fp = fopen($dest, 'w');
            if (!$fp) return '';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
            ]);
            $ok   = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if (!$ok || $code >= 400 || !file_exists($dest) || filesize($dest) === 0) {
                @unlink($dest);
                return '';
            }
            return 'bot_files/' . $name;
        } catch (\Throwable $e) {
            if (!empty($dest) && file_exists($dest)) @unlink($dest);
            return '';
        }
    }
}
