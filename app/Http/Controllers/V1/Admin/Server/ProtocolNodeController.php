<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Protocols\Mihomo\Outbound;
use App\Protocols\Mihomo\ManagedServer;
use App\Protocols\Mihomo\ProtocolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Shared CRUD mechanics; each URL fixes the native model and protocol. */
abstract class ProtocolNodeController extends Controller
{
    protected $type;

    private function model(): string
    {
        $table = ProtocolRegistry::table($this->type);
        $columns = array_merge(['host', 'port', 'server_port', 'parent_id'],
            array_keys(ProtocolRegistry::all()[$this->type]['fields']));
        abort_unless(Schema::hasTable($table) && Schema::hasColumns($table, $columns),
            503, 'ابتدا به‌روزرسانی پایگاه دادهٔ پنل را اجرا کنید.');
        return ProtocolRegistry::model($this->type);
    }

    public function save(Request $request)
    {
        $model = $this->model();
        $fields = ProtocolRegistry::all()[$this->type]['fields'];
        // Global trim/null middleware must not rewrite passwords, PEM or JSON.
        if ($request->isJson()) {
            $raw = json_decode($request->getContent(), true);
            if (is_array($raw)) $request->merge(array_intersect_key($raw, $fields));
        }
        $rules = [
            'id' => 'nullable|integer|min:1', 'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|min:1',
            'group_id' => 'required|array|min:1', 'group_id.*' => 'required|integer|distinct|exists:v2_server_group,id',
            'route_id' => 'nullable|array', 'route_id.*' => 'required|integer|distinct|exists:v2_server_route,id',
            'host' => 'nullable|string|max:255', 'port' => 'nullable|integer|between:1,65535',
            'server_port' => 'nullable|integer|between:1,65535', 'rate' => 'required|numeric|between:0,99999999.99',
            'tags' => 'nullable|array|max:20', 'tags.*' => 'required|string|max:100',
            'sort' => 'required|integer|between:0,2147483647', 'show' => 'required|boolean',
        ];
        if (ManagedServer::supports($this->type)) $rules['managed'] = 'sometimes|boolean';
        foreach ($fields as $column => $field) $rules[$column] = 'sometimes|nullable|' . [
            'string' => 'string|max:65535', 'int' => 'integer', 'bool' => 'boolean', 'json' => 'array',
        ][$field['kind']];
        $unknown = array_diff(array_keys($request->except('user')), array_keys($rules));
        if ($unknown) throw ValidationException::withMessages([reset($unknown) => 'این فیلد متعلق به این پروتکل نیست.']);
        $params = $request->validate($rules);
        if (ManagedServer::supports($this->type)) abort_unless(Schema::hasColumn(ProtocolRegistry::table($this->type), 'managed'), 503, 'ابتدا به‌روزرسانی پایگاه دادهٔ پنل را اجرا کنید.');
        abort_if(strlen(json_encode($params)) > 131072, 422, 'تنظیمات بیش از حد بزرگ است.');
        $params['group_id'] = array_map('strval', $params['group_id']);
        $params['route_id'] = array_map('strval', $params['route_id'] ?? []);
        $params['tags'] = $params['tags'] ?? [];
        DB::transaction(function () use ($model, $params) {
            // Serialize hierarchy writes so concurrent reparent/drop cannot
            // leave a cycle or a dangling parent in this protocol table.
            $rows = $model::orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $node = isset($params['id']) ? ($rows[$params['id']] ?? null) : new $model();
            abort_unless($node, 404);
            unset($params['id']);
            $node->fill($params);
            $id = $node->id ?? 0;
            $rows[$id] = $node;
            $data = $rows->map->toArray()->all();
            // Validate descendants too: changing a parent's options must not
            // invalidate relays using a different connection endpoint.
            $affected = [$id => true];
            do {
                $count = count($affected);
                foreach ($data as $rowId => $row) if (isset($affected[$row['parent_id'] ?? -1])) $affected[$rowId] = true;
            } while (count($affected) !== $count);
            foreach (array_intersect_key($data, $affected) as $rowId => $row) {
                $effective = Outbound::inherit($this->type, $row, $data);
                ManagedServer::validate($this->type, $effective);
                $settings = ProtocolRegistry::options($this->type, $effective);
                if (!empty($effective['managed'])) $settings = ManagedServer::credentials($settings);
                Outbound::validate($this->type, $settings);
            }
            $node->save();
        });
        return response(['data' => true]);
    }

    public function update(Request $request)
    {
        $model = $this->model();
        $params = $request->validate(['id' => 'required|integer|min:1', 'show' => 'required|boolean']);
        $model::findOrFail($params['id'])->update(['show' => $params['show']]);
        return response(['data' => true]);
    }

    public function drop(Request $request)
    {
        $model = $this->model();
        $params = $request->validate(['id' => 'required|integer|min:1']);
        DB::transaction(function () use ($model, $params) {
            $nodes = $model::orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless(isset($nodes[$params['id']]), 404);
            if ($nodes->contains(function ($node) use ($params) { return (int)$node->parent_id === (int)$params['id']; })) {
                throw ValidationException::withMessages(['parent_id' => 'ابتدا نودهای فرزند را حذف یا به والد دیگری متصل کنید.']);
            }
            $nodes[$params['id']]->delete();
        });
        return response(['data' => true]);
    }

    public function copy(Request $request)
    {
        $model = $this->model();
        $params = $request->validate(['id' => 'required|integer|min:1']);
        DB::transaction(function () use ($model, $params) {
            $nodes = $model::orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless(isset($nodes[$params['id']]), 404);
            $copy = $nodes[$params['id']]->replicate();
            $copy->show = 0;
            $copy->name = mb_substr($copy->name, 0, 240) . ' (copy)';
            $copy->save();
        });
        return response(['data' => true]);
    }
}
