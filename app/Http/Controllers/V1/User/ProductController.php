<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDelivery;
use App\Models\User;
use App\Services\ProductOrderService;
use App\Services\UserService;
use Illuminate\Http\Request;

/** The store's digital goods, for buyers. See ProductOrderService. */
class ProductController extends Controller
{
    public function fetch(Request $request)
    {
        if (!ProductOrderService::enabled()) return response(['data' => []]);
        $products = Product::where('show', 1)->orderBy('sort')->orderBy('id')->get();
        return response(['data' => $products->map(fn ($p) => ProductOrderService::storeView($p))->values()]);
    }

    public function order(Request $request)
    {
        if (!ProductOrderService::enabled()) abort(503, 'فروشگاه در دسترس نیست');
        $product = Product::find((int)$request->input('product_id'));
        if (!$product) abort(404, 'این محصول در دسترس نیست');
        if ((new UserService())->isNotCompleteOrderByUserId($request->user['id'])) {
            abort(500, __('You have an unpaid or pending order, please try again later or cancel it'));
        }
        $user = User::find($request->user['id']);
        if (!$user) abort(403, 'وارد نشده‌اید یا نشست منقضی شده است');
        $useWallet = $request->input('use_wallet', true);
        $useWallet = !in_array($useWallet, [false, 0, '0', 'false', 'no'], true);
        $order = ProductOrderService::create($user, $product, $useWallet);
        return response(['data' => $order->trade_no]);
    }

    /** What this account bought: one row per paid product order, no secrets. */
    public function purchases(Request $request)
    {
        if (!ProductOrderService::enabled()) return response(['data' => []]);
        $orders = Order::where('user_id', $request->user['id'])
            ->where('period', ProductOrderService::PERIOD)
            ->where('status', 3)
            ->orderBy('paid_at', 'DESC')
            ->limit(200)
            ->get();
        $deliveries = ProductDelivery::whereIn('order_id', $orders->pluck('id'))->get()->keyBy('order_id');
        return response(['data' => $orders->map(function (Order $order) use ($deliveries) {
            $snapshot = json_decode((string)$order->product_snapshot, true) ?: [];
            $delivery = $deliveries->get($order->id);
            return [
                'trade_no' => $order->trade_no,
                'product_id' => (int)$order->product_id,
                'name' => $snapshot['name'] ?? '',
                'category' => $snapshot['category'] ?? null,
                'image' => $snapshot['image'] ?? null,
                'paid_at' => $order->paid_at,
                'status' => $delivery && (int)$delivery->status === ProductDelivery::DELIVERED ? 'delivered' : 'waiting',
            ];
        })->values()]);
    }

    /** One purchase with what it delivered. */
    public function delivery(Request $request)
    {
        $order = Order::where('user_id', $request->user['id'])
            ->where('trade_no', (string)$request->input('trade_no'))
            ->first();
        if (!$order || !ProductOrderService::isProduct($order)) abort(404, 'سفارش یافت نشد');
        ProductOrderService::present($order);
        return response(['data' => [
            'trade_no' => $order->trade_no,
            'status' => (int)$order->status,
            'product' => $order->product,
            'paid_at' => $order->paid_at,
            'delivery' => ProductOrderService::deliveryView($order),
        ]]);
    }
}
