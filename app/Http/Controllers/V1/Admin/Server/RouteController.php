<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RouteController extends Controller
{
    public function fetch(Request $request)
    {
        $routes = ServerRoute::get();
        foreach ($routes as $k => $route) {
            $array = json_decode($route->match, true);
            if (is_array($array)) $routes[$k]['match'] = $array;
        }
        return [
            'data' => $routes
        ];
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'remarks' => 'required',
            'match' => 'array|required_unless:action,default_out',
            'action' => 'required|in:block,block_ip,block_port,protocol,dns,route,route_ip,default_out',
            'action_value' => 'nullable'
        ], [
            'remarks.required' => 'توضیحات نمی‌تواند خالی باشد',
            'match.required_unless' => 'مقدار تطبیق نمی‌تواند خالی باشد',
            'action.required' => 'نوع عملیات نمی‌تواند خالی باشد',
            'action.in' => 'نوع عملیات نامعتبر است'
        ]);
        if (($params['action'] ?? '') === 'default_out') {
            $normalizedMatch = [];
        } else {
            $normalizedMatch = array_filter((array)($params['match'] ?? []));
        }
        $params['match'] = json_encode($normalizedMatch);
        if ($request->input('id')) {
            try {
                $route = ServerRoute::find($request->input('id'));
                $route->update($params);
                return [
                    'data' => true
                ];
            } catch (\Exception $e) {
                abort(500, 'ذخیره ناموفق بود');
            }
        }
        if (!ServerRoute::create($params)) abort(500, 'ساخت ناموفق بود');
        return [
            'data' => true
        ];
    }

    public function drop(Request $request)
    {
        $route = ServerRoute::find($request->input('id'));
        if (!$route) abort(500, 'مسیر یافت نشد');
        if (!$route->delete()) abort(500, 'حذف ناموفق بود');
        return [
            'data' => true
        ];
    }
}
