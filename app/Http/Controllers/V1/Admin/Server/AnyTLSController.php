<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerAnytls;
use App\Utils\Helper;
use Illuminate\Http\Request;
use ParagonIE_Sodium_Compat as SodiumCompat;

class AnyTLSController extends Controller
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
            'tunnel_host' => 'nullable',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'server_name' => 'nullable',
            'insecure' => 'required|in:0,1',
            'padding_scheme' => 'nullable',
            // anytls is never plaintext, so 1 and 2 are the only valid modes.
            // Both are optional: the shipped admin bundle is compiled and its
            // anytls form has no TLS section, so it simply never sends them -
            // and validate() drops what is absent, leaving an existing row's
            // values untouched on update. Callers that DO send them (the app's
            // admin screen, or a direct API call) get the full behaviour.
            'tls' => 'nullable|in:1,2',
            'tls_settings' => 'nullable|array',
        ]);

        if (isset($params['padding_scheme'])) {
            $params['padding_scheme'] = json_decode($params['padding_scheme']);
        }

        // Key material is generated here, not asked for. Nobody should have to
        // paste an X25519 keypair into a form, and the compiled admin UI could
        // not offer one anyway. Mirrors V2nodeController::save so the two node
        // types produce byte-identical tls_settings.
        if (isset($params['tls']) && (int)$params['tls'] === 2) {
            $params['tls_settings'] = $params['tls_settings'] ?? [];
            if (!isset($params['tls_settings']['private_key'])) {
                $keyPair = SodiumCompat::crypto_box_keypair();
                $params['tls_settings']['public_key'] = Helper::base64EncodeUrlSafe(
                    SodiumCompat::crypto_box_publickey($keyPair)
                );
                $params['tls_settings']['private_key'] = Helper::base64EncodeUrlSafe(
                    SodiumCompat::crypto_box_secretkey($keyPair)
                );
            }
            if (!isset($params['tls_settings']['short_id'])) {
                $params['tls_settings']['short_id'] = substr(sha1($params['tls_settings']['private_key']), 0, 8);
            }
            // The borrowed site. REALITY completes a real handshake against it,
            // so it must be a reachable TLS 1.3 host - and it is what a censor
            // sees instead of our own domain.
            //
            // 🔴 It must also be a host our USERS can reach. This defaulted to
            // www.microsoft.com, and Microsoft geo-blocks Iran: a TLS connection
            // carrying that SNI from an Iranian network is a connection to a
            // place the user is not supposed to be able to reach, and it does not
            // survive. Every node created here inherited that, and the symptom
            // was the worst kind - a prober got a flawless certificate chain, so
            // the node looked perfect from outside, while no real client could
            // connect. It cost most of a day to find.
            //
            // A CDN that Iranian websites themselves depend on is the safer
            // class of choice: it cannot be filtered without breaking the local
            // web, it does not sanction-block, and a long-lived connection to it
            // is what every browser does all day anyway.
            if (empty($params['tls_settings']['server_name'])) {
                $params['tls_settings']['server_name'] = 'cdnjs.cloudflare.com';
            }
            if (empty($params['tls_settings']['server_port'])) {
                $params['tls_settings']['server_port'] = '443';
            }
        }

        // ECH is independent of the mode above: it encrypts the SNI of our OWN
        // certificate, so it belongs with tls=1. Enabling it on a REALITY node
        // buys nothing, since REALITY already presents a different name.
        if (!empty($params['tls_settings']['ech']) && $params['tls_settings']['ech'] === 'custom') {
            if (empty($params['tls_settings']['ech_server_name'])) {
                // No cover name means no ECH: the public_name is what a client
                // falls back to when ECH is rejected, so a blank one would
                // produce a config that fails closed rather than open.
                $params['tls_settings']['ech'] = '';
            } elseif (empty($params['tls_settings']['ech_key']) || empty($params['tls_settings']['ech_config'])) {
                $pair = Helper::generateEchKeyPair($params['tls_settings']['ech_server_name']);
                $params['tls_settings']['ech_key'] = $params['tls_settings']['ech_key'] ?: $pair['ech_key'];
                $params['tls_settings']['ech_config'] = $params['tls_settings']['ech_config'] ?: $pair['ech_config'];
            }
        }

        if ($request->input('id')) {
            $server = ServerAnytls::find($request->input('id'));
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

        if (!ServerAnytls::create($params)) {
            abort(500, 'ساخت ناموفق بود');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerAnytls::find($request->input('id'));
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

        $server = ServerAnytls::find($request->input('id'));

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
        $server = ServerAnytls::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, 'سرور یافت نشد');
        }
        if (!ServerAnytls::create($server->toArray())) {
            abort(500, 'کپی ناموفق بود');
        }

        return response([
            'data' => true
        ]);
    }
}
