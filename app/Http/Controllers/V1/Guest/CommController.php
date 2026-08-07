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
                return [
                    'version' => (int)($parsed['version'] ?? 1),
                    // Keys starting with _ are notes for whoever edits the file;
                    // they are not settings and never reach a device.
                    'settings' => (object)array_filter(
                        $parsed['settings'],
                        fn($key) => strncmp($key, '_', 1) !== 0,
                        ARRAY_FILTER_USE_KEY
                    ),
                ];
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

    private function getEmailSuffix()
    {
        $suffix = config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
}
