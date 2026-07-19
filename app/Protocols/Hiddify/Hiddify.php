<?php

namespace App\Protocols\Hiddify;

use App\Protocols\Singbox\Singbox;

class Hiddify extends Singbox
{
    public $flag = 'hiddify';
    private static $templateCache = [];

    protected function loadConfig()
    {
        $default = base_path('resources/rules/default.hiddify.json');
        $custom = base_path('resources/rules/custom.hiddify.json');
        $path = file_exists($custom) ? $custom : $default;
        $mtime = @filemtime($path);
        if (isset(self::$templateCache[$path]) && self::$templateCache[$path]['mtime'] === $mtime) {
            return self::$templateCache[$path]['data'];
        }
        $config = json_decode(file_get_contents($path), true);
        self::$templateCache[$path] = ['mtime' => $mtime, 'data' => $config];
        return $config;
    }

    protected function addProxies($proxies)
    {
        $outbounds = parent::addProxies($proxies);
        // Hiddify بخش dns را با تنظیمات خودش جایگزین می‌کند؛ پس ارجاع
        // domain_resolver به تگ local می‌شکند. آن را از همهٔ outboundها حذف می‌کنیم.
        foreach ($outbounds as &$ob) {
            if (is_array($ob)) {
                unset($ob['domain_resolver']);
            }
        }
        unset($ob);
        return $outbounds;
    }
}
