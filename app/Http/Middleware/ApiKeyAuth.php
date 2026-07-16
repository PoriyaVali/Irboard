<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('X-API-Key') ?? $request->query('api_key');
        $validKey = config('app.plan_api_key');

        if (!$validKey || $apiKey !== $validKey) {
            return response()->json([
                'success' => false,
                'message' => 'API Key نامعتبر'
            ], 401);
        }

        return $next($request);
    }
}
