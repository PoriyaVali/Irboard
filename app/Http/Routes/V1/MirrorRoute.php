<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

/**
 * The one route the Iranian relay calls.
 *
 * Its own group rather than a line in GuestRoute: the caller is not a guest and
 * not a user, it authenticates with a shared secret nothing else uses, and
 * keeping it separate means nobody widens `guest` by accident and exposes an
 * export of every subscription on the panel.
 *
 * RouteServiceProvider globs this directory, so there is nothing to register.
 */
class MirrorRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'mirror',
            'middleware' => 'mirror',
        ], function ($router) {
            $router->get('/export', 'V1\\Mirror\\MirrorController@export');
        });
    }
}
