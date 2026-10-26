<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMihomo;
use App\Protocols\Mihomo\Outbound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MihomoController extends Controller
{
    private function ready(): void
    {
        abort_unless(Schema::hasTable('v2_server_mihomo'), 503, 'ابتدا به‌روزرسانی پایگاه دادهٔ پنل را اجرا کنید.');
    }

    public function fetch()
    {
        $this->ready();
        return response(['data' => [
            'version' => Outbound::VERSION, 'protocols' => Outbound::catalog(),
            'nodes' => ServerMihomo::orderBy('sort')->orderBy('id')->get(),
        ]]);
    }

    public function save(Request $request)
    {
        $this->ready();
        // Laravel's global string transforms are useful for ordinary forms,
        // but JSON outbound values (including empty strings and whitespace in
        // credentials) belong to the client protocol. Validate the raw object.
        if ($request->isJson()) {
            $body = json_decode($request->getContent(), true);
            if (is_array($body) && array_key_exists('outbound_settings', $body)) {
                $request->merge(['outbound_settings' => $body['outbound_settings']]);
            }
        }
        $params = $request->validate([
            'id' => 'nullable|integer|min:1', 'name' => 'required|string|max:255',
            'protocol' => ['required', Rule::in(array_keys(Outbound::catalog()))],
            'group_id' => 'required|array|min:1', 'group_id.*' => 'required|integer|distinct|exists:v2_server_group,id',
            'tags' => 'nullable|array|max:20', 'tags.*' => 'required|string|max:100',
            'sort' => 'required|integer|between:0,2147483647', 'show' => 'required|boolean',
            'outbound_settings' => 'present|array',
        ]);
        abort_if(strlen(json_encode($params['outbound_settings'])) > 131072, 422, 'تنظیمات بیش از حد بزرگ است.');
        $params['outbound_settings'] = Outbound::validate($params['protocol'], $params['outbound_settings']);
        $params['group_id'] = array_map('strval', $params['group_id']);
        $params['tags'] = $params['tags'] ?? [];
        $server = isset($params['id']) ? ServerMihomo::findOrFail($params['id']) : new ServerMihomo();
        unset($params['id']);
        $server->fill($params)->save();
        return response(['data' => true]);
    }

    public function update(Request $request)
    {
        $this->ready();
        $params = $request->validate(['id' => 'required|integer|min:1', 'show' => 'required|boolean']);
        ServerMihomo::findOrFail($params['id'])->update(['show' => $params['show']]);
        return response(['data' => true]);
    }

    public function drop(Request $request)
    {
        $this->ready();
        $params = $request->validate(['id' => 'required|integer|min:1']);
        ServerMihomo::findOrFail($params['id'])->delete();
        return response(['data' => true]);
    }

    public function copy(Request $request)
    {
        $this->ready();
        $params = $request->validate(['id' => 'required|integer|min:1']);
        $copy = ServerMihomo::findOrFail($params['id'])->replicate();
        $copy->show = 0;
        $copy->name = mb_substr($copy->name, 0, 240) . ' (copy)';
        $copy->save();
        return response(['data' => true]);
    }
}
