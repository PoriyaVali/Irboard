<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDelivery;
use App\Models\ProductItem;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Digital goods: Apple IDs, gift cards, servers, anything an admin sells.
 *
 * A product order is an ordinary order (period `product`, plan 0) with the
 * product and its price snapshotted, so every way of paying an order -
 * gateways, card-to-card, the wallet - pays it unchanged. When it is paid,
 * OrderService::openOnce() hands it here:
 *
 *  - stock: the unit reserved when the order was made (or any unit left)
 *    becomes the buyer's; with none left the order waits for the admin;
 *  - manual: the order waits until the admin fills it in (a server);
 *  - unlimited: everyone receives the product's own content.
 *
 * Unit data, unlimited content and what a buyer received are stored
 * encrypted with the application key, and returned only to that buyer and
 * to admins.
 */
class ProductOrderService
{
    public const PERIOD = 'product';
    public const ORDER_TYPE = 10;

    /** Field types the app knows how to show. */
    public const FIELD_TYPES = ['text', 'secret', 'date', 'link', 'note'];

    public static function enabled(): bool
    {
        return Schema::hasTable('v2_product') && Schema::hasTable('v2_product_item')
            && Schema::hasTable('v2_product_delivery') && Schema::hasColumn('v2_order', 'product_id');
    }

    public static function isProduct(Order $order): bool
    {
        return $order->period === self::PERIOD && (int)$order->product_id > 0;
    }

    // ── Fields and encrypted data ──────────────────────────────────────────

    /**
     * The admin's field list, cleaned: keys are short identifiers, labels are
     * required, types fall back to text. Duplicate keys keep the first.
     *
     * @return array<int, array{key: string, label: string, type: string, secret: bool}>
     */
    public static function normalizeFields($fields): array
    {
        if (is_string($fields)) $fields = json_decode($fields, true);
        if (!is_array($fields)) return [];
        $out = [];
        $seen = [];
        foreach ($fields as $field) {
            if (!is_array($field)) continue;
            $label = trim((string)($field['label'] ?? ''));
            $key = strtolower(trim((string)($field['key'] ?? '')));
            $key = preg_replace('/[^a-z0-9_]/', '_', $key);
            if ($key === '' && $label !== '') $key = 'f' . (count($out) + 1);
            if ($key === '' || $label === '' || isset($seen[$key])) continue;
            $type = (string)($field['type'] ?? 'text');
            if (!in_array($type, self::FIELD_TYPES, true)) $type = 'text';
            $seen[$key] = true;
            $out[] = [
                'key' => substr($key, 0, 32),
                'label' => mb_substr($label, 0, 64),
                'type' => $type,
                'secret' => $type === 'secret' || !empty($field['secret']),
            ];
            if (count($out) >= 20) break;
        }
        return $out;
    }

    /** Only the product's own fields, as strings, trimmed. */
    public static function cleanData(array $fields, $data): array
    {
        if (is_string($data)) $data = json_decode($data, true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($fields as $field) {
            $value = $data[$field['key']] ?? null;
            if ($value === null || is_array($value)) continue;
            $value = trim((string)$value);
            if ($value !== '') $out[$field['key']] = mb_substr($value, 0, 2000);
        }
        return $out;
    }

    public static function seal(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public static function open(?string $sealed): array
    {
        if ($sealed === null || $sealed === '') return [];
        try {
            $data = json_decode(Crypt::decryptString($sealed), true);
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Stock pasted by an admin: one unit per line, values in the order of the
     * product's fields, separated by `|` or a tab. A line that is a JSON
     * object is read by field key instead. Blank lines are skipped; a line
     * with no value at all is reported, not stored.
     *
     * @return array{units: array<int, array>, rejected: array<int, int>}
     */
    public static function parseImport(array $fields, string $text): array
    {
        $units = [];
        $rejected = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($line[0] === '{') {
                $data = self::cleanData($fields, $line);
            } else {
                $parts = preg_split('/\t|\|/', $line);
                $data = [];
                foreach ($fields as $i => $field) {
                    if (!array_key_exists($i, $parts)) break;
                    $data[$field['key']] = $parts[$i];
                }
                // More values than fields: the rest belongs to the last one
                // (a note with a `|` in it).
                if (count($parts) > count($fields) && $fields) {
                    $last = $fields[count($fields) - 1]['key'];
                    $rest = array_slice($parts, count($fields) - 1);
                    // Only separators left (`|||`) is no value at all.
                    $data[$last] = trim(implode('', $rest)) === '' ? '' : implode('|', $rest);
                }
                $data = self::cleanData($fields, $data);
            }
            if (!$data) {
                $rejected[] = $index + 1;
                continue;
            }
            $units[] = $data;
        }
        return ['units' => $units, 'rejected' => $rejected];
    }

    // ── The store ──────────────────────────────────────────────────────────

    public static function available(Product $product): int
    {
        if ($product->delivery !== Product::DELIVERY_STOCK) return -1; // unlimited / made to order
        return ProductItem::where('product_id', $product->id)->where('status', ProductItem::AVAILABLE)->count();
    }

    /** A product as the store shows it: no unit data, no unlimited content. */
    public static function storeView(Product $product): array
    {
        $available = self::available($product);
        return [
            'id' => (int)$product->id,
            'name' => $product->name,
            'category' => $product->category,
            'description' => $product->description,
            'image' => $product->image,
            'price' => (int)$product->price,
            'delivery' => $product->delivery,
            'fields' => array_map(fn ($f) => ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type']],
                self::normalizeFields($product->fields)),
            'in_stock' => $available !== 0,
            // The exact count only when it is low: it tells a buyer to hurry
            // without telling a competitor the size of the stock.
            'remaining' => ($available >= 0 && $available <= max(1, (int)$product->low_stock)) ? $available : null,
        ];
    }

    private static function fail(int $status, string $message): void
    {
        abort($status, $message);
    }

    /**
     * Create the order for one unit of [product], reserving a unit of stock
     * so two buyers cannot pay for the last one. [useWallet] works as on a
     * plan order.
     */
    public static function create(User $user, Product $product, bool $useWallet): Order
    {
        if (!$product->show) self::fail(404, 'این محصول در دسترس نیست');
        if ((int)$product->price < 0) self::fail(422, 'قیمت این محصول معتبر نیست');
        if ($product->per_user_limit !== null) {
            $bought = Order::where('user_id', $user->id)->where('product_id', $product->id)
                ->whereIn('status', [0, 1, 3])->count();
            if ($bought >= (int)$product->per_user_limit) self::fail(422, 'به سقف خرید این محصول رسیده‌اید');
        }

        DB::beginTransaction();
        try {
            $order = new Order();
            $order->user_id = $user->id;
            $order->plan_id = 0;
            $order->product_id = $product->id;
            $order->product_snapshot = json_encode([
                'name' => $product->name,
                'category' => $product->category,
                'price' => (int)$product->price,
                'delivery' => $product->delivery,
                'image' => $product->image,
            ], JSON_UNESCAPED_UNICODE);
            $order->period = self::PERIOD;
            $order->type = self::ORDER_TYPE;
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = (int)$product->price;
            $order->exchange_rate = ExchangeService::getCurrentRate();

            if ($useWallet && $user->balance > 0 && $order->total_amount > 0 && !$user->is_staff) {
                $userService = new UserService();
                $take = min((int)$user->balance, (int)$order->total_amount);
                if (!$userService->addBalance($user->id, -$take)) self::fail(500, 'موجودی کافی نیست');
                $order->balance_amount = $take;
                $order->total_amount -= $take;
            }
            (new OrderService($order))->setInvite($user);
            if (!$order->save()) self::fail(500, 'ثبت سفارش ناموفق بود');

            if ($product->delivery === Product::DELIVERY_STOCK) {
                $item = ProductItem::where('product_id', $product->id)
                    ->where('status', ProductItem::AVAILABLE)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if (!$item) self::fail(422, 'موجودی این محصول تمام شده است');
                $item->status = ProductItem::RESERVED;
                $item->order_id = $order->id;
                $item->reserved_at = time();
                $item->save();
            }
            DB::commit();
            return $order;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /** A cancelled order gives its reserved unit back to the shelf. */
    public static function release(Order $order): void
    {
        if (!self::isProduct($order) || !self::enabled()) return;
        ProductItem::where('order_id', $order->id)
            ->where('status', ProductItem::RESERVED)
            ->update(['status' => ProductItem::AVAILABLE, 'order_id' => null, 'reserved_at' => null, 'updated_at' => time()]);
    }

    /**
     * The paid order: deliver what the product delivers, once. Runs inside
     * OrderService::open()'s per-order lock; the unique order_id of the
     * delivery row makes a second run a no-op as well.
     */
    public static function fulfil(Order $order): void
    {
        DB::beginTransaction();
        try {
            $fresh = Order::lockForUpdate()->find($order->id);
            if (!$fresh || (int)$fresh->status === 3) {
                DB::rollBack();
                return;
            }
            if (ProductDelivery::where('order_id', $order->id)->exists()) {
                $fresh->status = 3;
                $fresh->save();
                DB::commit();
                return;
            }
            $product = Product::find($order->product_id);
            $delivery = new ProductDelivery();
            $delivery->order_id = $order->id;
            $delivery->user_id = $order->user_id;
            $delivery->product_id = (int)$order->product_id;
            $delivery->status = ProductDelivery::WAITING;

            // The mode the order was sold under: an admin may switch the
            // product to another mode between ordering and payment.
            $snapshot = json_decode((string)$order->product_snapshot, true) ?: [];
            $mode = $snapshot['delivery'] ?? ($product->delivery ?? null);
            // A unit this order reserved is the buyer's whatever the mode is
            // now; after a late payment on a cancelled stock order, any unit
            // still on the shelf.
            $item = ProductItem::where('order_id', $order->id)->where('status', ProductItem::RESERVED)->lockForUpdate()->first();
            if (!$item && $product && $mode === Product::DELIVERY_STOCK) {
                $item = ProductItem::where('product_id', $product->id)->where('status', ProductItem::AVAILABLE)
                    ->orderBy('id')->lockForUpdate()->first();
            }
            if ($item) {
                $item->status = ProductItem::SOLD;
                $item->order_id = $order->id;
                $item->sold_at = time();
                $item->save();
                $delivery->item_id = $item->id;
                $delivery->data = $item->data;
                $delivery->status = ProductDelivery::DELIVERED;
                $delivery->delivered_at = time();
            } elseif ($product && $mode === Product::DELIVERY_UNLIMITED && $product->content) {
                $delivery->data = $product->content;
                $delivery->status = ProductDelivery::DELIVERED;
                $delivery->delivered_at = time();
            }
            // Otherwise the order waits for the admin, like a manual one.
            $delivery->save();
            $fresh->status = 3;
            $fresh->save();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (isset($delivery) && (int)$delivery->status === ProductDelivery::WAITING) {
            self::notifyAdmins($order, $product ?? null);
        }
    }

    /** The admin delivers a waiting order (a server, or stock that ran out). */
    public static function deliver(ProductDelivery $delivery, array $data, ?string $note): void
    {
        $product = Product::find($delivery->product_id);
        $fields = $product ? self::normalizeFields($product->fields) : [];
        $clean = $fields ? self::cleanData($fields, $data) : [];
        if (!$clean) abort(422, 'حداقل یکی از فیلدها را پر کنید');
        $delivery->data = self::seal($clean);
        $delivery->note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null;
        $delivery->status = ProductDelivery::DELIVERED;
        $delivery->delivered_at = time();
        $delivery->save();
    }

    /**
     * What the buyer of [order] sees: the fields of the product as it was
     * sold, with their values, or that it is still being prepared.
     */
    public static function deliveryView(Order $order): ?array
    {
        $delivery = ProductDelivery::where('order_id', $order->id)->first();
        if (!$delivery) return null;
        $product = Product::find($order->product_id);
        $fields = $product ? self::normalizeFields($product->fields) : [];
        $values = (int)$delivery->status === ProductDelivery::DELIVERED ? self::open($delivery->data) : [];
        $rows = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field['key'], $values)) continue;
            $rows[] = ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'],
                'secret' => $field['secret'], 'value' => $values[$field['key']]];
        }
        // Values for fields the admin removed later are still the buyer's.
        foreach ($values as $key => $value) {
            if (!in_array($key, array_column($rows, 'key'), true)) {
                $rows[] = ['key' => $key, 'label' => $key, 'type' => 'text', 'secret' => false, 'value' => $value];
            }
        }
        return [
            'status' => (int)$delivery->status === ProductDelivery::DELIVERED ? 'delivered' : 'waiting',
            'fields' => $rows,
            'note' => $delivery->note,
            'delivered_at' => $delivery->delivered_at,
        ];
    }

    /** The order list and detail show a product order as what it is. */
    public static function present(Order $order): void
    {
        if (!self::isProduct($order)) return;
        $snapshot = json_decode((string)$order->product_snapshot, true) ?: [];
        $order->setAttribute('is_product', true);
        $order->setAttribute('product', [
            'id' => (int)$order->product_id,
            'name' => $snapshot['name'] ?? '',
            'category' => $snapshot['category'] ?? null,
            'image' => $snapshot['image'] ?? null,
            'delivery' => $snapshot['delivery'] ?? null,
        ]);
        $order->setAttribute('plan', [
            'id' => 0,
            'name' => $snapshot['name'] ?? '',
            'transfer_enable' => 0,
            'is_wallet_recharge' => false,
            'is_product' => true,
        ]);
        if ((int)$order->status === 3) {
            $delivery = ProductDelivery::where('order_id', $order->id)->first();
            $order->setAttribute('delivery_status', $delivery && (int)$delivery->status === ProductDelivery::DELIVERED ? 'delivered' : 'waiting');
        }
        $order->makeHidden(['product_snapshot']);
    }

    private static function notifyAdmins(Order $order, ?Product $product): void
    {
        try {
            $name = $product ? $product->name : ('#' . $order->product_id);
            (new TelegramService())->sendMessageWithAdmin(
                "🛍 سفارش محصول منتظر تحویل\nمحصول: {$name}\nسفارش: {$order->trade_no}");
        } catch (\Throwable $e) {
            // A notice is a convenience; the order is in the admin's list.
        }
    }
}
