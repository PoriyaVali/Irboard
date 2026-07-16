<?php

namespace App\Http\Middleware;

use Closure;

/**
 * Restores the $_SERVER entries AdapterMan leaves out.
 *
 * Under php-fpm every request gets a full $_SERVER. AdapterMan rebuilds it from
 * scratch per request (src/Http.php) out of a fixed list of keys taken from the HTTP
 * request itself, so the CGI-style entries that describe the *script* — PHP_SELF,
 * SCRIPT_NAME, SCRIPT_FILENAME, DOCUMENT_ROOT — simply never exist. Setting them once
 * at worker boot does not help: the whole array is reassigned on the next request.
 *
 * Anything that reads those keys unguarded therefore throws, and the caller usually
 * has no idea why. The case that made this worth fixing: Symfony's
 * DumpCompletionCommand::configure() reads $_SERVER['PHP_SELF'], and Artisan::call()
 * configures every registered command before it runs one — so ANY Artisan::call() from
 * a controller died with "Undefined array key PHP_SELF". ConfigController@save calls
 * config:cache right after writing config/v2board.php: the file changed, the cache was
 * never rebuilt, and every worker went on booting the old settings. Settings looked
 * saved and never took effect. ThemeService, ThemeController and ServerConfigController
 * call Artisan the same way.
 *
 * This runs in the global stack, so it lands before any route, controller or command.
 */
class AdapterManServerVars
{
    public function handle($request, Closure $next)
    {
        if (!isset($_SERVER['SCRIPT_FILENAME'])) {
            $_SERVER['SCRIPT_FILENAME'] = base_path('artisan');
        }
        if (!isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = 'artisan';
        }
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'artisan';
        }
        if (!isset($_SERVER['DOCUMENT_ROOT'])) {
            $_SERVER['DOCUMENT_ROOT'] = base_path('public');
        }

        return $next($request);
    }
}
