<?php

namespace App\Http\Middleware;

use Closure;

/**
 * Guards the relay's export with a shared secret.
 *
 * This is not a user credential and deliberately not an admin one either. The
 * caller is a machine in Iran running a cron; giving it an admin token would
 * mean that whoever takes that box can administer the panel, and the export is
 * the only thing it ever needs.
 *
 * `hash_equals` because the comparison is against a secret and `===` on strings
 * returns as soon as two bytes differ. Over a link this slow that timing is
 * almost certainly unmeasurable - but "almost certainly" is a strange thing to
 * rely on when the correct call is the same length.
 */
class MirrorSecret
{
    public function handle($request, Closure $next)
    {
        $expected = (string) config('mirror.sync_secret', '');

        // An unset secret refuses everything rather than accepting everything.
        // The other direction would turn a missing line in .env into an open
        // dump of every subscription on the panel.
        if ($expected === '') {
            abort(503, 'mirror export is not configured');
        }

        $given = (string) $request->header('x-mirror-secret', '');

        if ($given === '' || !hash_equals($expected, $given)) {
            abort(403, 'mirror secret is wrong');
        }

        return $next($request);
    }
}
