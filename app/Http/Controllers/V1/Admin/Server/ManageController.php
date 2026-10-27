<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $serverService = new ServerService();
        return response([
            'data' => $serverService->getAllServers()
        ]);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '5m');
        $types = [
            'shadowsocks',
            'vmess',
            'vless',
            'trojan',
            'tuic',
            'hysteria',
            'anytls',
            'v2node',
            'mdns',
            'trusttunnel',
        ];
        $types = array_merge($types, \App\Protocols\Mihomo\ProtocolRegistry::types());
        $params = $request->only($types);
        if (empty($params)) {
            foreach ($types as $type) {
                if (isset($_POST[$type])) $params[$type] = $_POST[$type];
            }
        }
        $rules = [];
        foreach ($types as $type) {
            $rules[$type] = 'sometimes|array';
            $rules[$type . '.*'] = 'integer|between:0,2147483647';
        }
        $params = Validator::make($params, $rules)->validate();
        foreach ($params as $type => $positions) {
            foreach ($positions as $id => $sort) {
                if (!ctype_digit((string)$id) || (int)$id < 1) {
                    throw ValidationException::withMessages([$type => 'شناسهٔ نود معتبر نیست.']);
                }
            }
        }
        DB::transaction(function () use ($params) {
            foreach ($params as $type => $positions) {
                $model = 'App\\Models\\Server' . ucfirst($type);
                foreach ($positions as $id => $sort) {
                    if (!$model::findOrFail($id)->update(['sort' => $sort])) abort(500, 'ذخیره ناموفق بود');
                }
            }
        });
        return response([
            'data' => true
        ]);
    }
}
