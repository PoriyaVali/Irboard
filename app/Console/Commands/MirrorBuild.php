<?php

namespace App\Console\Commands;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\V1\User\UserController;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Renders the snapshot the Iranian relay serves when it cannot reach us.
 *
 * ## Why this is a command and not the endpoint
 *
 * The export has to render each user's subscription, and the Clash family of
 * renderers calls the global `header()` rather than building a response. Doing
 * that inside an HTTP request would push `subscription-userinfo` and
 * `content-disposition` onto the export's own response - and under webman,
 * where a worker outlives the request, onto whatever request came next. In CLI
 * `header()` is inert, so rendering belongs here and the endpoint only reads
 * what this wrote.
 *
 * It is also where the time is. Measured on this panel, not estimated: a full
 * build is **70 seconds** for the 336 accounts that have a live plan, and 33
 * seconds when most of them turn out to be unchanged. (An earlier estimate of
 * 39s was wrong because it counted only `getAvailableServers` at 96ms per user
 * and forgot the three renders and the writes on top.) Fine hourly in the
 * background; not fine while an HTTP worker is held open.
 *
 * ⚠️ Rather less of that is skippable than it looks. `getSubscribe` embeds the
 * live device count and `info` the last login, so a payload changes for its own
 * reasons: a second run one minute later still rebuilt 89 of 336. The hash
 * check saves the write, never the render.
 *
 * ## 🔑 The bodies are produced by the real controllers
 *
 * Not rebuilt here from the same tables. The whole value of the mirror is that
 * it answers *what the panel would have answered*, and a second implementation
 * drifts - silently, and only visibly during an outage, which is the worst
 * moment to find out. Calling UserController and ClientController costs a
 * synthetic Request and removes the entire class of bug.
 *
 * ## 🔑 Digests, never credentials
 *
 * A user is found by the sha256 of whatever they present - the JWT for the API
 * calls, the subscription token for the configuration file. The relay only ever
 * compared those; it never needed the plaintext. So it is sent digests, and a
 * box in Iran that nobody logs into holds nothing it could replay.
 */
class MirrorBuild extends Command
{
    protected $signature = 'mirror:build
        {--user= : build one account by id, for checking a change}
        {--limit=0 : stop after this many accounts (0 = all)}';

    protected $description = 'Render the relay\'s offline snapshot into v2_mirror_export';

    public function handle()
    {
        $flags = (array) config('mirror.flags', []);
        if (empty($flags)) {
            $this->error('  mirror.flags is empty - nothing to render');
            return 1;
        }

        $now = time();
        $built = 0;
        $unchanged = 0;
        $failed = 0;
        $limit = (int) $this->option('limit');

        $t0 = microtime(true);

        $this->eligible()->chunkById(100, function ($users) use (
            $flags, $now, $limit, &$built, &$unchanged, &$failed
        ) {
            foreach ($users as $user) {
                if ($limit > 0 && ($built + $unchanged) >= $limit) {
                    return false;
                }
                try {
                    $payload = $this->payloadFor($user, $flags);
                } catch (\Throwable $e) {
                    // One account that cannot be rendered must not cost the
                    // other 335 theirs. Its previous row stays exactly where it
                    // is - stale is worth more than absent to someone who is
                    // offline right now.
                    $failed++;
                    $this->warn("  user {$user->id}: " . substr($e->getMessage(), 0, 120));
                    continue;
                }

                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $hash = hash('sha256', $json);

                $existing = DB::table('v2_mirror_export')
                    ->where('user_id', $user->id)
                    ->value('payload_hash');

                if ($existing === $hash) {
                    // Nothing about this account moved. Skipping the write
                    // keeps built_at meaning "when this content was last
                    // confirmed correct" rather than "when the command last
                    // ran", and keeps a mostly idle table out of the binlog.
                    $unchanged++;
                    continue;
                }

                DB::table('v2_mirror_export')->updateOrInsert(
                    ['user_id' => $user->id],
                    ['payload' => $json, 'payload_hash' => $hash, 'built_at' => $now]
                );
                $built++;
            }
        });

        // Accounts that expired or were banned since the last build. Their rows
        // would otherwise sit there forever, and the relay would keep serving a
        // subscription to someone who no longer has one.
        $dropped = DB::table('v2_mirror_export')
            ->whereNotIn('user_id', $this->eligible()->select('id'))
            ->delete();

        $this->info(sprintf(
            '  %d built, %d unchanged, %d dropped, %d failed in %.1fs',
            $built, $unchanged, $dropped, $failed, microtime(true) - $t0
        ));

        return 0;
    }

    /**
     * Who is worth mirroring.
     *
     * Only an account with a live plan: the mirror exists so that someone who
     * has paid can still fetch what they paid for, and there is nothing to
     * serve an account without a subscription. On this panel that is 336 users
     * out of 3167, which is most of why a full build fits in well under a
     * minute.
     */
    private function eligible()
    {
        $now = time();

        if ($id = $this->option('user')) {
            return User::where('id', (int) $id);
        }

        return User::where('banned', 0)
            ->whereNotNull('plan_id')
            ->where(function ($q) use ($now) {
                $q->where('expired_at', '>', $now)->orWhereNull('expired_at');
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(User $user, array $flags): array
    {
        $payload = [
            'user_id' => $user->id,
            'api_subjects' => $this->apiSubjects($user),
            'subscribes' => [],
        ];

        // The two authenticated reads. `user` on the request is the array the
        // User middleware puts there - AuthService::decryptAuthData returns
        // exactly these four columns - so the controllers see what they always
        // see.
        $auth = [
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'is_staff' => $user->is_staff,
        ];
        $controller = new UserController();

        $payload['getSubscribe'] = $this->body(
            $controller->getSubscribe($this->request('/api/v1/user/getSubscribe', [], $auth))
        );
        $payload['info'] = $this->body(
            $controller->info($this->request('/api/v1/user/info', [], $auth))
        );
        // 🔑 Tiny, and the customer site does not work without it.
        //
        // drmobjay.com's own Vue front-end calls checkLogin on every page load
        // to decide whether it has a session. Unanswered, it concludes the
        // person is signed out and shows a login form they cannot get past -
        // during an outage where their subscription was sitting right here,
        // ready to serve. A site that loads and then locks you out is worse
        // than one that does not load, because it looks like your account is
        // gone.
        //
        // It costs nothing to mirror: the response is three booleans read
        // straight off the auth data, with no query behind it.
        $payload['checkLogin'] = $this->body(
            $controller->checkLogin($this->request('/api/v1/user/checkLogin', [], $auth))
        );

        // The configuration file, once per client bucket. The Client middleware
        // puts the User *model* on the request here, not the array - the
        // renderers and ServerService both want the model - so this mirrors
        // that difference deliberately.
        $client = new ClientController();
        foreach ($flags as $bucket => $flag) {
            $request = $this->request(
                '/api/v1/client/subscribe',
                ['token' => $user->token, 'flag' => $flag],
                $user
            );
            $response = $client->subscribe($request);

            $payload['subscribes'][] = [
                'bucket' => $bucket,
                'subject' => hash('sha256', $user->token . '|' . $bucket),
                'body' => $this->body($response),
                'content_type' => $this->contentType($response),
            ];
        }

        return $payload;
    }

    /**
     * The sha256 of every live session token this account has.
     *
     * One account can have several - a phone, a desktop, a browser - and the
     * same answer serves all of them. Sessions live in Redis and the JWT is the
     * key the app presents, so hashing each one is what lets the relay match a
     * caller without ever being told who they are.
     *
     * @return list<string>
     */
    private function apiSubjects(User $user): array
    {
        $subjects = [];
        foreach ((new AuthService($user))->getSessions() as $meta) {
            if (!empty($meta['auth_data'])) {
                $subjects[] = hash('sha256', $meta['auth_data']);
            }
        }
        return array_values(array_unique($subjects));
    }

    /**
     * A request shaped like the one the middleware would have handed on.
     *
     * @param  array<string, mixed>  $query
     * @param  mixed  $user  the array for user routes, the model for client ones
     */
    private function request(string $path, array $query, $user): Request
    {
        $request = Request::create($path, 'GET', $query);
        $request->merge(['user' => $user]);
        return $request;
    }

    private function body($response): string
    {
        if (is_object($response) && method_exists($response, 'getContent')) {
            return (string) $response->getContent();
        }
        return (string) $response;
    }

    private function contentType($response): string
    {
        if (is_object($response) && method_exists($response, 'headers')) {
            // Symfony responses expose headers as a bag; the Clash family sets
            // its content type through the global header() instead, which is
            // inert in CLI - so those come back as the default and the relay
            // serves text/plain, which is what a Clash client is reading anyway.
            $type = $response->headers->get('Content-Type');
            if ($type) {
                return $type;
            }
        }
        return 'text/plain; charset=utf-8';
    }
}
