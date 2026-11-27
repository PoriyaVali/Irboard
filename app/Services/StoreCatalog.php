<?php

namespace App\Services;

/**
 * The store's banners and downloadable apps: one list, managed on the admin
 * "Store" page, shown by the website's store and by the app's store tab (the
 * app receives it inside its config document, see CommController::appConfig).
 * Digital products live in their own tables (ProductOrderService) and are
 * shown in the same store next to these.
 *
 * Kept as storage/appstore/apps.json, where the earlier app-store page wrote
 * it, so nothing an operator already entered is lost.
 */
class StoreCatalog
{
    /** Categories that are platforms: the app shows their apps only there. */
    public const PLATFORMS = ['android', 'windows', 'ios', 'macos', 'linux'];

    public static function file(): string
    {
        return base_path('storage/appstore/apps.json');
    }

    public static function load(): array
    {
        $file = self::file();
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!is_array($data)) $data = [];
        return [
            'banners' => array_values(array_filter(is_array($data['banners'] ?? null) ? $data['banners'] : [], 'is_array')),
            'categories' => array_values(array_filter(is_array($data['categories'] ?? null) ? $data['categories'] : [], 'is_array')),
        ];
    }

    public static function save(array $data): void
    {
        $file = self::file();
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        clearstatcache(true, $file);
    }

    /** When the list last changed, so a cached document knows it is stale. */
    public static function version(): int
    {
        $file = self::file();
        return is_file($file) ? (int)filemtime($file) : 0;
    }

    public static function https($value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return preg_match('#^https://[^\s"\'<>]+$#i', $value) ? $value : null;
    }

    private static function text($value, int $max): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * Where a banner leads: a web address, a product ("product:12"), a
     * category of products ("category:apple-id") or a page of the website
     * ("#/dashboard/..."). Anything else is no link.
     */
    public static function link($value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if (preg_match('/^product:\d{1,10}$/', $value) || preg_match('/^category:[A-Za-z0-9_-]{1,64}$/', $value)) return $value;
        if (preg_match('~^#?/[A-Za-z0-9/_\-]{0,200}$~', $value)) return '#' . ltrim($value, '#');
        return self::https($value);
    }

    /** A banner as stored, from what the admin form sent. */
    public static function banner(array $input): array
    {
        $color = is_string($input['color'] ?? null) && preg_match('/^#[0-9a-f]{6}$/i', $input['color']) ? $input['color'] : '#6366f1';
        $gradient = is_string($input['gradient'] ?? null) && preg_match('/^linear-gradient\([#0-9a-z(),.%\s-]{1,200}\)$/i', trim($input['gradient']))
            ? trim($input['gradient']) : null;
        return array_filter([
            'id' => $input['id'] ?? null,
            'title' => self::text($input['title'] ?? null, 120),
            'description' => self::text($input['description'] ?? null, 300),
            'badge' => self::text($input['badge'] ?? null, 30),
            'color' => $color,
            'gradient' => $gradient,
            'image' => self::https($input['image'] ?? null),
            'link' => self::link($input['link'] ?? null),
            'enabled' => filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ], fn ($v) => $v !== null);
    }

    /** What the website's store shows: enabled banners, apps in order. */
    public static function forUsers(): array
    {
        $data = self::load();
        $banners = [];
        foreach ($data['banners'] as $b) {
            if (!filter_var($b['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) continue;
            $clean = self::banner($b);
            if (empty($clean['title']) && empty($clean['image'])) continue;
            unset($clean['enabled']);
            $banners[] = $clean;
        }
        $categories = [];
        foreach ($data['categories'] as $cat) {
            $apps = array_values(array_filter(is_array($cat['apps'] ?? null) ? $cat['apps'] : [], 'is_array'));
            usort($apps, fn ($a, $b) => ((int)($a['order'] ?? 99)) <=> ((int)($b['order'] ?? 99)));
            $categories[] = [
                'id' => (string)($cat['id'] ?? ''),
                'title' => (string)($cat['title'] ?? ($cat['id'] ?? '')),
                'icon' => (string)($cat['icon'] ?? '📦'),
                'apps' => $apps,
            ];
        }
        return ['banners' => $banners, 'categories' => $categories];
    }

    /**
     * The same list in the app's document format (`store`): banners with
     * where they lead, apps with https downloads and the platform their
     * category stands for. Empty when the admin has entered nothing, so an
     * older `store` section in the config file still applies.
     */
    public static function forApp(): array
    {
        $users = self::forUsers();
        $banners = [];
        foreach ($users['banners'] as $b) {
            $out = array_filter([
                'image' => $b['image'] ?? null,
                'title' => $b['title'] ?? null,
                'description' => $b['description'] ?? null,
                'color' => $b['color'] ?? null,
            ], fn ($v) => $v !== null);
            $link = $b['link'] ?? null;
            if ($link !== null && preg_match('/^product:(\d+)$/', $link, $m)) $out['product'] = (int)$m[1];
            elseif ($link !== null && preg_match('/^category:(.+)$/', $link, $m)) $out['category'] = $m[1];
            elseif ($link !== null && self::https($link)) $out['link'] = $link;
            $banners[] = $out;
        }
        $apps = [];
        foreach ($users['categories'] as $cat) {
            $platform = in_array(strtolower($cat['id']), self::PLATFORMS, true) ? strtolower($cat['id']) : null;
            foreach ($cat['apps'] as $a) {
                $name = self::text($a['name'] ?? null, 80);
                $url = self::https($a['downloadUrl'] ?? ($a['url'] ?? null));
                if ($name === null || $url === null) continue;
                $apps[] = array_filter([
                    'id' => self::text($a['id'] ?? null, 64) ?? $name,
                    'name' => $name,
                    'url' => $url,
                    'description' => self::text($a['description'] ?? null, 500),
                    'icon' => self::https($a['icon'] ?? null),
                    'version' => self::text($a['version'] ?? null, 32),
                    'size' => self::text($a['size'] ?? null, 32),
                    'platforms' => $platform ? [$platform] : null,
                ], fn ($v) => $v !== null);
            }
        }
        $out = [];
        if ($banners) $out['banners'] = $banners;
        if ($apps) $out['apps'] = $apps;
        return $out;
    }
}
