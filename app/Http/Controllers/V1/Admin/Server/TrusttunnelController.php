<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerTrusttunnel;
use Illuminate\Http\Request;

class TrusttunnelController extends Controller
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
            'hostname' => 'nullable',
            'cert_type' => 'nullable|in:self-signed,letsencrypt,provided',
            'acme_email' => 'nullable|email',
            'cert_chain_path' => 'nullable',
            'cert_key_path' => 'nullable',
            'custom_sni' => 'nullable',
            // 🔑 The form's Select sends "" for "not set", and `boolean`
            // rejects an empty string even under `nullable` - which is the
            // validation.boolean the panel showed on save. `in:` accepts every
            // shape the two Selects can produce, and the cast below turns them
            // into real booleans for the column.
            'anti_dpi' => 'nullable|in:0,1,"0","1",true,false',
            'client_random_prefix' => 'nullable',
            // ⚠️ validate() is a WHITELIST: a field missing here is silently
            // dropped, so the new options would have saved as nothing at all
            // even without an error message to notice.
            'upstream_protocol' => 'nullable|in:,http2,http3',
            'has_ipv6' => 'nullable|in:0,1,"0","1",true,false',
            'dns_upstreams' => 'nullable|string',
        ]);

        // Normalise what the Selects send. The column is tinyint(1) and the
        // model casts to boolean, so "" and null both have to become 0 rather
        // than reaching the database as an empty string.
        foreach (['anti_dpi', 'has_ipv6'] as $flag) {
            $params[$flag] = empty($params[$flag]) ? 0 : 1;
        }
        if (!isset($params['upstream_protocol'])) {
            $params['upstream_protocol'] = '';
        }

        // The certificate hostname is what the endpoint serves TLS for. Falling
        // back to the address means a node whose certificate matches its own
        // host needs nothing filled in, which is the common case.
        if (empty($params['hostname'])) {
            $params['hostname'] = $params['host'];
        }
        if (empty($params['cert_type'])) {
            $params['cert_type'] = 'self-signed';
        }

        // Each certificate mode needs different things, and a node that starts
        // without them fails at the endpoint with an error the admin never
        // sees. Refuse here, where there is somewhere to say why.
        if ($params['cert_type'] === 'letsencrypt' && empty($params['acme_email'])) {
            abort(500, 'برای Let\'s Encrypt باید ایمیل وارد شود');
        }
        if ($params['cert_type'] === 'provided'
            && (empty($params['cert_chain_path']) || empty($params['cert_key_path']))) {
            abort(500, 'برای گواهی دستی باید مسیر گواهی و کلید وارد شود');
        }

        if ($request->input('id')) {
            $server = ServerTrusttunnel::find($request->input('id'));
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

        if (!ServerTrusttunnel::create($params)) {
            abort(500, 'ساخت ناموفق بود');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $server = ServerTrusttunnel::find($request->input('id'));
        if (!$server) {
            abort(500, 'شناسه‌ی نود یافت نشد');
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

        $server = ServerTrusttunnel::find($request->input('id'));

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
        $server = ServerTrusttunnel::find($request->input('id'));
        if (!$server) {
            abort(500, 'سرور یافت نشد');
        }
        $server->show = 0;
        if (!ServerTrusttunnel::create($server->toArray())) {
            abort(500, 'کپی ناموفق بود');
        }

        return response([
            'data' => true
        ]);
    }
}
