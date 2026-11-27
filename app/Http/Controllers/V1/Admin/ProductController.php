<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDelivery;
use App\Models\ProductItem;
use App\Models\User;
use App\Services\ProductOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Digital goods for admins: products and their fields, stock, and the orders
 * waiting to be delivered by hand. See ProductOrderService.
 */
class ProductController extends Controller
{
    private function ready(): void
    {
        if (!ProductOrderService::enabled()) {
            abort(503, 'جدول‌های فروشگاه هنوز ساخته نشده‌اند؛ دستور به‌روزرسانی پنل را اجرا کنید');
        }
    }

    public function fetch(Request $request)
    {
        $this->ready();
        $counts = ProductItem::select('product_id', 'status', DB::raw('count(*) as n'))
            ->groupBy('product_id', 'status')->get();
        $waiting = ProductDelivery::where('status', ProductDelivery::WAITING)
            ->select('product_id', DB::raw('count(*) as n'))->groupBy('product_id')->pluck('n', 'product_id');
        $products = Product::orderBy('sort')->orderBy('id')->get()->map(function (Product $product) use ($counts, $waiting) {
            $stock = ['available' => 0, 'reserved' => 0, 'sold' => 0, 'withdrawn' => 0];
            foreach ($counts->where('product_id', $product->id) as $row) {
                $key = [ProductItem::AVAILABLE => 'available', ProductItem::RESERVED => 'reserved',
                    ProductItem::SOLD => 'sold', ProductItem::WITHDRAWN => 'withdrawn'][(int)$row->status] ?? null;
                if ($key) $stock[$key] = (int)$row->n;
            }
            $data = $product->toArray();
            $data['fields'] = ProductOrderService::normalizeFields($product->fields);
            $data['content'] = $product->delivery === Product::DELIVERY_UNLIMITED ? ProductOrderService::open($product->content) : null;
            $data['stock'] = $stock;
            $data['waiting'] = (int)($waiting[$product->id] ?? 0);
            $data['low'] = $product->delivery === Product::DELIVERY_STOCK && $stock['available'] <= (int)$product->low_stock;
            return $data;
        });
        return response(['data' => $products->values()]);
    }

    public function save(Request $request)
    {
        $this->ready();
        $name = trim((string)$request->input('name', ''));
        if ($name === '') abort(422, 'نام محصول را وارد کنید');
        $price = $request->input('price');
        if (!is_numeric($price) || (int)$price < 0) abort(422, 'قیمت معتبر نیست');
        $delivery = (string)$request->input('delivery', Product::DELIVERY_STOCK);
        if (!in_array($delivery, Product::DELIVERIES, true)) abort(422, 'نوع تحویل معتبر نیست');
        $fields = ProductOrderService::normalizeFields($request->input('fields'));
        if (!$fields) abort(422, 'حداقل یک فیلد تعریف کنید');
        $image = trim((string)$request->input('image', ''));
        if ($image !== '' && !preg_match('#^https://[^\s]+$#i', $image)) abort(422, 'نشانی تصویر باید با https شروع شود');

        $product = $request->input('id') ? Product::find((int)$request->input('id')) : new Product();
        if (!$product) abort(404, 'محصول یافت نشد');
        $product->name = mb_substr($name, 0, 255);
        $product->category = trim((string)$request->input('category', '')) ?: null;
        $product->description = trim((string)$request->input('description', '')) ?: null;
        $product->image = $image ?: null;
        $product->price = (int)$price;
        $product->delivery = $delivery;
        $product->fields = $fields;
        $limit = $request->input('per_user_limit');
        $product->per_user_limit = ($limit === null || $limit === '' || (int)$limit <= 0) ? null : (int)$limit;
        $product->low_stock = max(0, (int)$request->input('low_stock', 3));
        $product->show = $request->boolean('show');
        $product->sort = (int)$request->input('sort', 0);
        if ($delivery === Product::DELIVERY_UNLIMITED) {
            $content = ProductOrderService::cleanData($fields, $request->input('content'));
            if (!$content) abort(422, 'محتوای تحویل را پر کنید');
            $product->content = ProductOrderService::seal($content);
        } else {
            $product->content = null;
        }
        $product->save();
        return response(['data' => (int)$product->id]);
    }

    public function drop(Request $request)
    {
        $this->ready();
        $product = Product::find((int)$request->input('id'));
        if (!$product) abort(404, 'محصول یافت نشد');
        // Sold orders point at it: hide it instead, so their history stays readable.
        if (Order::where('product_id', $product->id)->exists()) {
            $product->show = false;
            $product->save();
            return response(['data' => true, 'message' => 'این محصول فروش داشته است؛ مخفی شد و حذف نشد']);
        }
        DB::transaction(function () use ($product) {
            ProductItem::where('product_id', $product->id)->delete();
            $product->delete();
        });
        return response(['data' => true]);
    }

    /** The units of one product, with their data (admins only). */
    public function items(Request $request)
    {
        $this->ready();
        $product = Product::find((int)$request->input('product_id'));
        if (!$product) abort(404, 'محصول یافت نشد');
        $current = max(1, (int)$request->input('current', 1));
        $pageSize = min(100, max(10, (int)$request->input('pageSize', 20)));
        $query = ProductItem::where('product_id', $product->id)->orderByDesc('id');
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $query->where('status', (int)$request->input('status'));
        }
        $total = (clone $query)->count();
        $items = $query->forPage($current, $pageSize)->get();
        $orders = Order::whereIn('id', $items->pluck('order_id')->filter())->get(['id', 'trade_no', 'user_id'])->keyBy('id');
        $emails = User::whereIn('id', $orders->pluck('user_id'))->pluck('email', 'id');
        return response([
            'data' => $items->map(function (ProductItem $item) use ($orders, $emails) {
                $order = $item->order_id ? $orders->get($item->order_id) : null;
                return [
                    'id' => (int)$item->id,
                    'status' => (int)$item->status,
                    'data' => ProductOrderService::open($item->data),
                    'trade_no' => $order->trade_no ?? null,
                    'email' => $order ? ($emails[$order->user_id] ?? null) : null,
                    'created_at' => $item->created_at,
                    'sold_at' => $item->sold_at,
                ];
            })->values(),
            'total' => $total,
        ]);
    }

    /** Stock pasted in bulk; see ProductOrderService::parseImport. */
    public function import(Request $request)
    {
        $this->ready();
        $product = Product::find((int)$request->input('product_id'));
        if (!$product) abort(404, 'محصول یافت نشد');
        $fields = ProductOrderService::normalizeFields($product->fields);
        $parsed = ProductOrderService::parseImport($fields, (string)$request->input('text', ''));
        if (!$parsed['units']) abort(422, 'هیچ واحد معتبری پیدا نشد');
        if (count($parsed['units']) > 2000) abort(422, 'در هر بار حداکثر ۲۰۰۰ واحد');
        $now = time();
        DB::transaction(function () use ($parsed, $product, $now) {
            foreach (array_chunk($parsed['units'], 200) as $chunk) {
                ProductItem::insert(array_map(fn ($data) => [
                    'product_id' => $product->id,
                    'data' => ProductOrderService::seal($data),
                    'status' => ProductItem::AVAILABLE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });
        return response(['data' => ['added' => count($parsed['units']), 'rejected' => $parsed['rejected']]]);
    }

    /** Correct a unit still on the shelf, or take it off. */
    public function itemUpdate(Request $request)
    {
        $this->ready();
        // Under the same row lock a purchase takes to reserve a unit, so a
        // unit cannot be changed after a buyer has reserved it.
        DB::transaction(function () use ($request) {
            $item = ProductItem::lockForUpdate()->find((int)$request->input('id'));
            if (!$item) abort(404, 'واحد یافت نشد');
            if (in_array((int)$item->status, [ProductItem::RESERVED, ProductItem::SOLD], true)) {
                abort(422, 'این واحد فروخته یا رزرو شده است و تغییر نمی‌کند');
            }
            if ($request->has('data')) {
                $product = Product::find($item->product_id);
                $clean = ProductOrderService::cleanData(ProductOrderService::normalizeFields($product->fields ?? []), $request->input('data'));
                if (!$clean) abort(422, 'حداقل یکی از فیلدها را پر کنید');
                $item->data = ProductOrderService::seal($clean);
            }
            if ($request->has('withdrawn')) {
                $item->status = $request->boolean('withdrawn') ? ProductItem::WITHDRAWN : ProductItem::AVAILABLE;
            }
            $item->save();
        });
        return response(['data' => true]);
    }

    public function itemDrop(Request $request)
    {
        $this->ready();
        $id = (int)$request->input('id');
        // One statement that checks and deletes: a unit reserved in between
        // is not touched.
        $deleted = ProductItem::where('id', $id)
            ->whereIn('status', [ProductItem::AVAILABLE, ProductItem::WITHDRAWN])
            ->delete();
        if (!$deleted) {
            if (!ProductItem::where('id', $id)->exists()) abort(404, 'واحد یافت نشد');
            abort(422, 'واحد فروخته یا رزروشده حذف نمی‌شود');
        }
        return response(['data' => true]);
    }

    /** Paid product orders; `status=0` for the ones waiting for an admin. */
    public function deliveries(Request $request)
    {
        $this->ready();
        $current = max(1, (int)$request->input('current', 1));
        $pageSize = min(100, max(10, (int)$request->input('pageSize', 20)));
        $query = ProductDelivery::orderByDesc('id');
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $query->where('status', (int)$request->input('status'));
        }
        if ($request->input('product_id')) $query->where('product_id', (int)$request->input('product_id'));
        $total = (clone $query)->count();
        $rows = $query->forPage($current, $pageSize)->get();
        $orders = Order::whereIn('id', $rows->pluck('order_id'))->get()->keyBy('id');
        $emails = User::whereIn('id', $rows->pluck('user_id'))->pluck('email', 'id');
        $products = Product::whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');
        return response([
            'data' => $rows->map(function (ProductDelivery $delivery) use ($orders, $emails, $products) {
                $order = $orders->get($delivery->order_id);
                $product = $products->get($delivery->product_id);
                $snapshot = $order ? (json_decode((string)$order->product_snapshot, true) ?: []) : [];
                return [
                    'id' => (int)$delivery->id,
                    'order_id' => (int)$delivery->order_id,
                    'trade_no' => $order->trade_no ?? null,
                    'email' => $emails[$delivery->user_id] ?? null,
                    'user_id' => (int)$delivery->user_id,
                    'product_id' => (int)$delivery->product_id,
                    'product' => $product->name ?? ($snapshot['name'] ?? ''),
                    'fields' => $product ? ProductOrderService::normalizeFields($product->fields) : [],
                    'status' => (int)$delivery->status,
                    'data' => ProductOrderService::open($delivery->data),
                    'note' => $delivery->note,
                    'amount' => $order ? (int)$order->total_amount + (int)$order->balance_amount : null,
                    'paid_at' => $order->paid_at ?? null,
                    'delivered_at' => $delivery->delivered_at,
                ];
            })->values(),
            'total' => $total,
        ]);
    }

    /** Fill in a waiting order (or correct a delivered one). */
    public function deliver(Request $request)
    {
        $this->ready();
        $delivery = ProductDelivery::find((int)$request->input('id'));
        if (!$delivery) abort(404, 'سفارش یافت نشد');
        $data = $request->input('data');
        if (is_string($data)) $data = json_decode($data, true);
        ProductOrderService::deliver($delivery, is_array($data) ? $data : [], $request->input('note'));
        return response(['data' => true]);
    }
}
