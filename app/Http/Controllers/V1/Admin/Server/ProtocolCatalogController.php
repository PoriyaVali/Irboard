<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Protocols\Mihomo\Outbound;
use App\Protocols\Mihomo\AdminForm;

class ProtocolCatalogController extends Controller
{
    public function fetch()
    {
        return response(['data' => ['version' => Outbound::VERSION, 'protocols' => AdminForm::catalog(Outbound::catalog())]]);
    }
}
