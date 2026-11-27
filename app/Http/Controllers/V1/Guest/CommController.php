<?php

namespace App\Http\Controllers\V1\Guest;

use App\Services\StoreCatalog;
use App\Http\Controllers\Controller;
use App\Utils\Dict;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CommController extends Controller
{
    public function config()
    {
        return response([
            'data' => [
                'tos_url' => config('v2board.tos_url'),
                'is_email_verify' => (int)config('v2board.email_verify', 0) ? 1 : 0,
                'is_invite_force' => (int)config('v2board.invite_force', 0) ? 1 : 0,
                'email_whitelist_suffix' => (int)config('v2board.email_whitelist_enable', 0)
                    ? $this->getEmailSuffix()
                    : 0,
                'is_recaptcha' => (int)config('v2board.recaptcha_enable', 0) ? 1 : 0,
                'recaptcha_site_key' => config('v2board.recaptcha_site_key'),
                'app_description' => config('v2board.app_description'),
                'app_url' => config('v2board.app_url'),
                'logo' => config('v2board.logo'),
            ]
        ]);
    }

    /**
     * Settings the Doctor Mobile app adopts as its defaults.
     *
     * Served from resources/rules/ next to the clash and sing-box templates and
     * edited the same way: copy default.dm-app.json to custom.dm-app.json and
     * change that. Public on purpose - it carries no user data and no secrets,
     * so it does not depend on a subscription token, which matters because with
     * show_subscribe_method 1 or 2 that token is single-use or short-lived and
     * could not be reused for a second request.
     *
     * The body is deterministic: the same file always produces the same bytes,
     * which is what lets a client skip a document it has already applied.
     */
    public function appConfig()
    {
        $custom = base_path('resources/rules/custom.dm-app.json');
        $default = base_path('resources/rules/default.dm-app.json');
        $path = file_exists($custom) ? $custom : $default;
        if (!file_exists($path)) {
            return response()->json(['version' => 0, 'settings' => (object)[]])
                ->header('Cache-Control', 'public, max-age=300');
        }

        $mtime = filemtime($path);
        $document = Cache::remember(
            'dm_app_config_' . md5($path) . '_' . $mtime,
            3600,
            function () use ($path) {
                $parsed = json_decode($this->stripJsonComments(file_get_contents($path)), true);
                if (!is_array($parsed) || !isset($parsed['settings']) || !is_array($parsed['settings'])) {
                    return null;
                }
                $document = [
                    'version' => (int)($parsed['version'] ?? 1),
                    'settings' => (object)$this->flattenAppSettings($parsed['settings']),
                ];
                // Which settings to put back even for users who changed them.
                // Only passed on when it says something, so a file that predates
                // the option behaves as it always did.
                $force = $parsed['force'] ?? false;
                if ($force === true || (is_array($force) && $force !== [])) {
                    $document['force'] = $force;
                }
                // Per-operator settings: the app knows which operator's network
                // it is on (SIM code on mobile data, the address's network on
                // Wi-Fi) and lays that operator's section over `settings`.
                // Passed on only when present, so older files are unchanged.
                $operators = $this->appOperatorSections($parsed['operators'] ?? null);
                if ($operators !== []) {
                    $document['operators'] = (object)$operators;
                }
                foreach (['operator_codes', 'operator_asns'] as $key) {
                    $map = $this->appStringMap($parsed[$key] ?? null);
                    if ($map !== []) {
                        $document[$key] = (object)$map;
                    }
                }
                // The app's store tab: banners and apps to download. Passed on
                // only when it has something, https addresses only.
                $store = $this->appStore($parsed['store'] ?? null);
                if ($store !== []) {
                    $document['store'] = $store;
                }
                return $document;
            }
        );

        // A malformed file must not blank out everyone's defaults, so say
        // nothing rather than something wrong: clients keep the last good
        // document they applied.
        if ($document === null) {
            return response()->json(['error' => 'app config is not valid json'], 500);
        }

        // The store tab shows what the admin "Store" page manages - the same
        // banners and apps as the website. The file's own `store` section is
        // only a fallback for a panel that has not entered any.
        if (StoreCatalog::configured()) {
            $catalog = StoreCatalog::forApp();
            if ($catalog !== []) {
                $document['store'] = $catalog;
            } else {
                unset($document['store']);
            }
        }

        return response()
            ->json($document, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'public, max-age=300')
            ->setEtag(md5($path . $mtime . '|' . StoreCatalog::revision()));
    }

    /**
     * Removes // and # comments so the settings file can carry notes.
     *
     * JSON has no comments, and this file is worth annotating: ninety-odd
     * values whose accepted forms are not guessable from the value alone. The
     * only thing that reads it is this controller, so it can allow them.
     *
     * Comments are stripped while tracking whether we are inside a string,
     * because half the values contain the very characters being looked for -
     * every http:// URL has a //, and stripping from there would silently
     * truncate the value rather than fail loudly.
     */
    private function stripJsonComments($json)
    {
        $out = '';
        $len = strlen($json);
        $inString = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];

            if ($inString) {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    // An escaped character cannot end the string, and that
                    // includes an escaped backslash before a closing quote.
                    $out .= $json[++$i];
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                $out .= $ch;
                continue;
            }

            $isLineComment = $ch === '#' || ($ch === '/' && $i + 1 < $len && $json[$i + 1] === '/');
            if ($isLineComment) {
                while ($i < $len && $json[$i] !== "\n") {
                    $i++;
                }
                // Keep the newline: it is not part of the comment, and dropping
                // it would join two lines that were never meant to be one.
                $out .= "\n";
                continue;
            }

            if ($ch === '/' && $i + 1 < $len && $json[$i + 1] === '*') {
                $end = strpos($json, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }

            $out .= $ch;
        }
        return $out;
    }

    /**
     * Turns the file's per-core sections into the flat map a device reads.
     *
     * The file groups settings by which core they belong to, because with
     * ninety-odd of them a flat list gives an operator no way to tell a
     * sing-box option from a mihomo one. Devices have no use for the grouping -
     * every setting is looked up by its own name - so it is undone here, which
     * also means the wire format never changed and older app builds are
     * unaffected by how the file happens to be arranged.
     *
     * A flat file still works: anything that is not a section is taken as a
     * setting. Keys starting with _ are notes for whoever edits the file, at
     * either level, and never reach a device.
     */
    /**
     * The `operators` map, each section as {"settings": {...}, "force": ...}.
     *
     * A section is named by an operator id (irancell, mci, shatel, ...), a
     * transport (cellular, wifi, ethernet) or both (wifi:irancell); the app
     * applies the most specific one that matches. Its settings are grouped and
     * commented like the top-level ones and flattened the same way; a section
     * without a "settings" key is read as the settings themselves. Keys
     * starting with "_" are notes for the editor, as everywhere in this file.
     */
    private function appOperatorSections($operators): array
    {
        if (!is_array($operators) || array_is_list($operators)) {
            return [];
        }
        $out = [];
        foreach ($operators as $name => $section) {
            $name = strtolower(trim((string)$name));
            if ($name === '' || strncmp($name, '_', 1) === 0 || !is_array($section) || array_is_list($section)) {
                continue;
            }
            $settings = isset($section['settings']) && is_array($section['settings'])
                ? $section['settings']
                : array_diff_key($section, ['force' => true]);
            $flat = $this->flattenAppSettings($settings);
            if ($flat === []) {
                continue;
            }
            $entry = ['settings' => (object)$flat];
            $force = $section['force'] ?? false;
            if ($force === true || (is_array($force) && $force !== [])) {
                $entry['force'] = $force;
            }
            $out[$name] = $entry;
        }
        ksort($out);
        return $out;
    }

    /**
     * The store section: {"banners": [{image, link?, title?}], "apps": [{id?,
     * name, url, description?, icon?, version?, size?, platforms?}]}. An entry
     * missing what it needs, or with an address that is not https, is dropped;
     * editor notes (keys starting with "_") too.
     */
    private function appStore($store): array
    {
        if (!is_array($store) || array_is_list($store)) {
            return [];
        }
        $https = function ($v) {
            if (!is_string($v)) return null;
            $v = trim($v);
            return preg_match('#^https://[^\s/]+#i', $v) ? $v : null;
        };
        $text = function ($v, int $max) {
            if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
            $v = trim((string)$v);
            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $banners = [];
        foreach (is_array($store['banners'] ?? null) ? $store['banners'] : [] as $b) {
            if (!is_array($b) || !($image = $https($b['image'] ?? null))) continue;
            $banners[] = array_filter([
                'image' => $image,
                'link' => $https($b['link'] ?? null),
                'title' => $text($b['title'] ?? null, 120),
            ], fn($v) => $v !== null);
        }
        $apps = [];
        foreach (is_array($store['apps'] ?? null) ? $store['apps'] : [] as $a) {
            if (!is_array($a)) continue;
            $name = $text($a['name'] ?? null, 80);
            $url = $https($a['url'] ?? null);
            if ($name === null || $url === null) continue;
            $platforms = [];
            foreach (is_array($a['platforms'] ?? null) ? $a['platforms'] : [] as $p) {
                if (in_array($p, ['android', 'windows'], true)) $platforms[] = $p;
            }
            $apps[] = array_filter([
                'id' => $text($a['id'] ?? null, 64) ?? $name,
                'name' => $name,
                'url' => $url,
                'description' => $text($a['description'] ?? null, 500),
                'icon' => $https($a['icon'] ?? null),
                'version' => $text($a['version'] ?? null, 32),
                'size' => $text($a['size'] ?? null, 32),
                'platforms' => $platforms ?: null,
            ], fn($v) => $v !== null);
        }
        $out = [];
        if ($banners) $out['banners'] = $banners;
        if ($apps) $out['apps'] = $apps;
        return $out;
    }

    /** A {key: "id"} map with string ids, editor notes dropped. */
    private function appStringMap($map): array
    {
        if (!is_array($map) || array_is_list($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $key => $value) {
            $key = trim((string)$key);
            if ($key === '' || strncmp($key, '_', 1) === 0 || !is_string($value) || trim($value) === '') {
                continue;
            }
            $out[$key] = strtolower(trim($value));
        }
        ksort($out);
        return $out;
    }

    private function flattenAppSettings(array $settings)
    {
        $flat = [];
        foreach ($settings as $key => $value) {
            if (strncmp($key, '_', 1) === 0) {
                continue;
            }
            // A section is a map; a setting's value may well be a list
            // (mdns_resolvers), which is why the two are told apart by shape
            // rather than by nesting depth.
            if (is_array($value) && !array_is_list($value)) {
                foreach ($value as $k => $v) {
                    if (strncmp($k, '_', 1) !== 0) {
                        $flat[$k] = $v;
                    }
                }
                continue;
            }
            $flat[$key] = $value;
        }
        return $flat;
    }

    private function getEmailSuffix()
    {
        $suffix = config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
}
