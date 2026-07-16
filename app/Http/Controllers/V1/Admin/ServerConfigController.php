<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ServerConfigController extends Controller
{
    public function status(Request $request)
    {
        $items = [];
        $basePath = base_path();

        // از agent status بخون
        $agentStatus = [];
        $statusFile = $basePath . '/storage/server_config_status.json';
        if (file_exists($statusFile)) {
            $agentStatus = json_decode(file_get_contents($statusFile), true) ?: [];
        }

        // ۱. force_https
        $v2config = @file_get_contents($basePath . '/config/v2board.php');
        preg_match("/'force_https'\s*=>\s*'(\d)'/", $v2config, $m);
        $items['force_https'] = [
            'current' => $m[1] ?? 'unknown',
            'expected' => '0',
            'ok' => ($m[1] ?? '') === '0',
            'label' => 'Force HTTPS',
            'desc' => 'باید 0 باشد تا API از HTTP کار کند',
        ];

        // ۲. TrustProxies
        $tp = @file_get_contents($basePath . '/app/Http/Middleware/TrustProxies.php');
        $hasWild = $tp && (strpos($tp, "\$proxies = '*'") !== false || strpos($tp, '$proxies = "*"') !== false);
        $items['trust_proxies'] = [
            'current' => $hasWild ? '*' : 'empty',
            'expected' => '*',
            'ok' => $hasWild,
            'label' => 'Trust Proxies',
            'desc' => 'باید * باشد تا هدرهای X-Forwarded قبول شوند',
        ];

        // ۳-۶ از agent
        $items['nginx_port_8443'] = [
            'current' => ($agentStatus['nginx_port_8443'] ?? false) ? 'active' : 'missing',
            'expected' => 'active',
            'ok' => $agentStatus['nginx_port_8443'] ?? false,
            'label' => 'Nginx Port 8443',
            'desc' => 'پورت HTTP بدون SSL برای SSH tunnel',
        ];

        $items['http_to_https'] = [
            'current' => ($agentStatus['http_to_https'] ?? false) ? 'conditional' : 'redirect-all',
            'expected' => 'conditional',
            'ok' => $agentStatus['http_to_https'] ?? false,
            'label' => 'HTTP→HTTPS Redirect',
            'desc' => 'ریدایرکت شرطی - مسیر API از HTTP بدون ریدایرکت',
        ];

        $items['fastcgi_https'] = [
            'current' => ($agentStatus['fastcgi_https'] ?? false) ? 'on' : 'default',
            'expected' => 'on',
            'ok' => $agentStatus['fastcgi_https'] ?? false,
            'label' => 'FastCGI HTTPS',
            'desc' => 'PHP باید همیشه HTTPS فرض کند',
        ];

        $items['firewall_8443'] = [
            'current' => ($agentStatus['firewall_8443'] ?? false) ? 'open' : 'closed',
            'expected' => 'open',
            'ok' => $agentStatus['firewall_8443'] ?? false,
            'label' => 'Port 8443',
            'desc' => 'پورت 8443 باید در دسترس باشد',
        ];

        // ۷. Payment Proxy
        $proxyEnable = \DB::table('v2_bot_settings')->where('key', 'payment_proxy_enable')->value('value') ?? '0';
        $proxyUrl = \DB::table('v2_bot_settings')->where('key', 'payment_proxy_url')->value('value') ?? '';
        $proxySecret = \DB::table('v2_bot_settings')->where('key', 'payment_proxy_secret')->value('value') ?? '';
        $items['payment_proxy'] = [
            'enabled' => $proxyEnable === '1',
            'url' => $proxyUrl,
            'secret' => $proxySecret ? '***' . substr($proxySecret, -4) : '',
            'label' => 'Payment Proxy',
            'desc' => 'پروکسی پرداخت برای مخفی کردن IP بکند',
        ];

        // ۸. nginx rewrite
        $items['nginx_rewrite'] = [
            'current' => (($agentStatus['nginx_rewrite_api'] ?? false) && ($agentStatus['nginx_rewrite_fcgi'] ?? false)) ? 'configured' : 'missing',
            'expected' => 'configured',
            'ok' => ($agentStatus['nginx_rewrite_api'] ?? false) && ($agentStatus['nginx_rewrite_fcgi'] ?? false),
            'label' => 'Nginx Rewrite',
            'desc' => 'تنظیمات rewrite برای API',
        ];

        // وضعیت agent
        $agentAlive = isset($agentStatus['updated_at']) && (time() - strtotime($agentStatus['updated_at'])) < 30;

        $allOk = true;
        foreach ($items as $v) {
            if (isset($v['ok']) && !$v['ok']) $allOk = false;
        }

        return response()->json([
            'data' => [
                'status' => $allOk ? 'ok' : 'needs_fix',
                'agent_alive' => $agentAlive,
                'agent_updated' => $agentStatus['updated_at'] ?? null,
                'items' => $items,
            ]
        ]);
    }

    public function apply(Request $request)
    {
        $target = $request->input('target', 'all');
        $results = [];
        $basePath = base_path();
        $needsAgent = false;

        if ($target === 'all' || $target === 'force_https') {
            $file = $basePath . '/config/v2board.php';
            $content = file_get_contents($file);
            $content = preg_replace("/'force_https'\s*=>\s*'1'/", "'force_https' => '0'", $content);
            file_put_contents($file, $content);
            $results['force_https'] = 'applied';
        }

        if ($target === 'all' || $target === 'trust_proxies') {
            $file = $basePath . '/app/Http/Middleware/TrustProxies.php';
            $content = file_get_contents($file);
            $content = str_replace('protected $proxies;', "protected \$proxies = '*';", $content);
            file_put_contents($file, $content);
            $results['trust_proxies'] = 'applied';
        }

        $sysTargets = ['nginx_port_8443', 'http_to_https', 'fastcgi_https', 'firewall_8443'];
        if ($target === 'all' || in_array($target, $sysTargets)) {
            $queue = $target === 'all' ? $sysTargets : [$target];
            file_put_contents($basePath . '/storage/server_config_queue.json', json_encode([
                'tasks' => $queue,
                'time' => time(),
            ]));
            $needsAgent = true;
            foreach ($queue as $t) $results[$t] = 'queued';
        }

        if ($target === 'all' || $target === 'payment_proxy') {
            $url = $request->input('proxy_url', '');
            $enable = $request->input('proxy_enable', '1');
            \DB::table('v2_bot_settings')->updateOrInsert(['key' => 'payment_proxy_url'], ['value' => $url]);
            \DB::table('v2_bot_settings')->updateOrInsert(['key' => 'payment_proxy_enable'], ['value' => $enable]);
            $results['payment_proxy'] = 'applied';
        }

        \Artisan::call('config:clear');
        \Artisan::call('cache:clear');
        $results['cache'] = 'cleared';

        return response()->json([
            'data' => [
                'results' => $results,
                'agent_pending' => $needsAgent,
                'message' => $needsAgent ? 'تغییرات سیستمی در صف قرار گرفت (۵ ثانیه صبر کنید)' : 'اعمال شد',
            ]
        ]);
    }
}
