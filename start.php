<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| our application. We just need to utilize it! We'll simply require it
| into the script here so that we don't have to worry about manual
| loading any of our classes later on. It feels great to relax.
|
*/

require __DIR__.'/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Turn On The Lights
|--------------------------------------------------------------------------
|
| We need to illuminate PHP development, so let us turn on the lights.
| This bootstraps the framework and gets it ready for use, then it
| will load up this application so that we can run it and send
| the responses back to the browser and delight our users.
|
*/

$app = require_once __DIR__.'/bootstrap/app.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request
| through the kernel, and send the associated response back to
| the client's browser allowing them to enjoy the creative
| and wonderful application we have prepared for them.
|
*/

global $kernel;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

function run()
{
    global $kernel;

    ob_start();

    $response = $kernel->handle(
        $request = Illuminate\Http\Request::capture()
    );

    $response->send();

    $kernel->terminate($request, $response);

    rollBackLeftoverTransactions();

    return ob_get_clean();
}

/**
 * A worker serves thousands of requests on one database connection. A request
 * that ends inside DB::beginTransaction() - an abort() between begin and
 * commit, e.g. a coupon refused while an order is being built - leaves that
 * transaction open, and under php-fpm the connection closing rolled it back.
 * Here the next requests on the worker would run inside it: their writes
 * never committed and vanished when the worker recycled. Close it now.
 */
function rollBackLeftoverTransactions()
{
    try {
        foreach (app('db')->getConnections() as $name => $connection) {
            $level = $connection->transactionLevel();
            if ($level > 0) {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                // Logged after the rollback: a log channel that writes to the
                // database would otherwise have its row rolled back too.
                \Illuminate\Support\Facades\Log::warning('Rolled back a transaction a request left open', [
                    'connection' => $name,
                    'level' => $level,
                ]);
            }
        }
    } catch (\Throwable $e) {
        // A broken connection is reconnected by the next query.
    }
}
