<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMdns;
use Illuminate\Http\Request;

class MdnsController extends Controller
{
    public function save(Request $request)
    {
        $params = $request->validate([
            'show' => '',
            'name' => 'required',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'domain' => 'required|array',
            'encryption_method' => 'required|integer',
            'encryption_key' => 'nullable',
        ]);

        // auto-generate a visible transport key when the admin leaves it empty
        // so it can be seen/copied in the panel and flows into the sub link.
        if (empty($params['encryption_key'])) {
            $method = (int) ($params['encryption_method'] ?? 2);
            $len = $method === 3 ? 16 : ($method === 4 ? 24 : 32);
            $params['encryption_key'] = bin2hex(random_bytes(intdiv($len, 2)));
        }

        if ($request->input('id')) {
            $server = ServerMdns::find($request->input('id'));
            if (!$server) {
                abort(500, 'سرور یافت نشد');
            }
            try {
                $server->update($params);
            } catch (\Exception $e) {
                abort(500, 'ذخیره ناموفق بود');
            }
            return response([
                'data' => true
            ]);
        }

        if (!ServerMdns::create($params)) {
            abort(500, 'ساخت ناموفق بود');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerMdns::find($request->input('id'));
            if (!$server) {
                abort(500, 'شناسه‌ی نود یافت نشد');
            }
        }
        return response([
            'data' => $server->delete()
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'show' => 'in:0,1'
        ], [
            'show.in' => 'فرمت وضعیت نمایش نادرست است'
        ]);
        $params = $request->only([
            'show',
        ]);

        $server = ServerMdns::find($request->input('id'));

        if (!$server) {
            abort(500, 'این سرور یافت نشد');
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, 'ذخیره ناموفق بود');
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerMdns::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, 'سرور یافت نشد');
        }
        if (!ServerMdns::create($server->toArray())) {
            abort(500, 'کپی ناموفق بود');
        }

        return response([
            'data' => true
        ]);
    }
}
