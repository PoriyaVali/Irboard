<?php

namespace App\Http\Controllers\V1\Guest;

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
                $parsed = json_decode(file_get_contents($path), true);
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
                return $document;
            }
        );

        // A malformed file must not blank out everyone's defaults, so say
        // nothing rather than something wrong: clients keep the last good
        // document they applied.
        if ($document === null) {
            return response()->json(['error' => 'app config is not valid json'], 500);
        }

        return response()
            ->json($document, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'public, max-age=300')
            ->setEtag(md5($path . $mtime));
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
