<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class TestRoute
{
    public function map(Registrar $router)
    {
        // تست API ساده
        $router->get('/test', function() {
            return response()->json([
                'success' => true,
                'message' => 'Backend API is working!',
                'server' => 'ddr.drmobilejayzan.info',
                'time' => now()->toDateTimeString()
            ]);
        });
    }
}
