<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDelivery;
use App\Models\ProductItem;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductOrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * Digital goods: a product order is paid like any order and delivered once,
 * from stock, by the admin, or as the same content for everyone.
 */
class ProductStoreTest extends TestCase
{
    use PanelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->createInstallTables(['v2_product', 'v2_product_item', 'v2_product_delivery']);
        DB::statement('CREATE UNIQUE INDEX product_trade_no ON v2_order (trade_no)');
        DB::statement('CREATE UNIQUE INDEX product_delivery_order ON v2_product_delivery (order_id)');
        config(['app.key' => 'base64:' . base64_encode(str_repeat('p', 32))]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    private function auth($user): array
    {
        $token = 'product-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function admin(string $action): string
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\ProductController@' . $action) return '/' . $route->uri();
        }
        $this->fail('Missing admin product route ' . $action);
    }

    private const FIELDS = [
        ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
        ['key' => 'password', 'label' => 'Password', 'type' => 'secret'],
        ['key' => 'birthday', 'label' => 'Birthday', 'type' => 'date'],
    ];

    private function product(array $attrs = []): Product
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        $id = $this->withHeaders($this->auth($admin))->postJson($this->admin('save'), array_merge([
            'name' => 'US Apple ID', 'category' => 'apple-id', 'price' => 250000, 'delivery' => 'stock',
            'fields' => self::FIELDS, 'show' => true, 'low_stock' => 2,
        ], $attrs))->assertOk()->json('data');
        return Product::find($id);
    }

    private function import(Product $product, string $text): array
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        return $this->withHeaders($this->auth($admin))->postJson($this->admin('import'), [
            'product_id' => $product->id, 'text' => $text,
        ])->assertOk()->json('data');
    }

    private function buy($user, Product $product, bool $useWallet = true): Order
    {
        $tradeNo = $this->withHeaders($this->auth($user))->postJson('/api/v1/user/product/order', [
            'product_id' => $product->id, 'use_wallet' => $useWallet,
        ])->assertOk()->json('data');
        return Order::where('trade_no', $tradeNo)->firstOrFail();
    }

    private function pay(Order $order): Order
    {
        (new OrderService($order))->paid('test');
        (new OrderService(Order::find($order->id)))->open();
        return Order::find($order->id);
    }

    public function testStockIsImportedEncryptedAndTheStoreShowsNoUnitData()
    {
        $product = $this->product();
        $result = $this->import($product, "a@example.com|pw1|2000-01-01\n\n   \n{\"email\":\"b@example.com\",\"password\":\"pw2\"}\n|||");
        $this->assertSame(2, $result['added']);
        $this->assertSame([5], $result['rejected']);
        $raw = DB::table('v2_product_item')->pluck('data')->implode(' ');
        $this->assertStringNotContainsString('pw1', $raw);
        $this->assertStringNotContainsString('a@example.com', $raw);

        $user = $this->makeUser();
        $store = $this->withHeaders($this->auth($user))->getJson('/api/v1/user/product/fetch')->assertOk()->json('data');
        $this->assertCount(1, $store);
        $this->assertTrue($store[0]['in_stock']);
        $this->assertSame(2, $store[0]['remaining']);
        $this->assertSame(['email', 'password', 'birthday'], array_column($store[0]['fields'], 'key'));
        $this->assertStringNotContainsString('pw', json_encode($store));
    }

    public function testAStockOrderReservesAUnitAndDeliversItOnceWhenPaid()
    {
        $product = $this->product();
        $this->import($product, "a@example.com|pw1|2000-01-01");
        $user = $this->makeUser();
        $order = $this->buy($user, $product);
        $this->assertSame(250000, (int)$order->total_amount);
        $this->assertSame(ProductItem::RESERVED, (int)ProductItem::first()->status);
        // The last unit is held: nobody else can order it meanwhile.
        $other = $this->makeUser();
        $this->withHeaders($this->auth($other))->postJson('/api/v1/user/product/order', ['product_id' => $product->id])->assertStatus(422);

        $order = $this->pay($order);
        $this->assertSame(3, (int)$order->status);
        $this->assertSame(ProductItem::SOLD, (int)ProductItem::first()->status);
        (new OrderService($order))->open(); // a second run changes nothing
        $this->assertSame(1, ProductDelivery::count());

        $view = $this->withHeaders($this->auth($user))->getJson('/api/v1/user/product/delivery?trade_no=' . $order->trade_no)
            ->assertOk()->json('data.delivery');
        $this->assertSame('delivered', $view['status']);
        $this->assertSame(['a@example.com', 'pw1', '2000-01-01'], array_column($view['fields'], 'value'));
        $this->assertTrue($view['fields'][1]['secret']);
        // Not someone else's.
        $this->withHeaders($this->auth($other))->getJson('/api/v1/user/product/delivery?trade_no=' . $order->trade_no)->assertStatus(404);
        // The order list names it as a product.
        $list = $this->withHeaders($this->auth($user))->getJson('/api/v1/user/order/fetch')->assertOk()->json('data');
        $this->assertTrue($list[0]['is_product']);
        $this->assertSame('US Apple ID', $list[0]['plan']['name']);
        $this->assertSame('delivered', $list[0]['delivery_status']);
    }

    public function testCancellingGivesTheUnitAndTheWalletBack()
    {
        $product = $this->product();
        $this->import($product, "a@example.com|pw1|2000-01-01");
        $user = $this->makeUser(['balance' => 100000]);
        $order = $this->buy($user, $product);
        $this->assertSame(100000, (int)$order->balance_amount);
        $this->assertSame(150000, (int)$order->total_amount);
        $this->assertSame(0, (int)DB::table('v2_user')->where('id', $user->id)->value('balance'));
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame(ProductItem::AVAILABLE, (int)ProductItem::first()->status);
        $this->assertNull(ProductItem::first()->order_id);
        $this->assertSame(100000, (int)DB::table('v2_user')->where('id', $user->id)->value('balance'));
    }

    public function testAnOrderPaidAfterTheStockRanOutWaitsForTheAdmin()
    {
        $product = $this->product(['delivery' => 'manual', 'name' => 'Germany VPS',
            'fields' => [['key' => 'ip', 'label' => 'IP'], ['key' => 'root', 'label' => 'Root password', 'type' => 'secret']]]);
        $user = $this->makeUser();
        $order = $this->pay($this->buy($user, $product));
        $this->assertSame(3, (int)$order->status);
        $view = $this->withHeaders($this->auth($user))->getJson('/api/v1/user/product/delivery?trade_no=' . $order->trade_no)->json('data.delivery');
        $this->assertSame('waiting', $view['status']);
        $this->assertSame([], $view['fields']);

        $admin = $this->makeUser(['is_admin' => 1]);
        $waiting = $this->withHeaders($this->auth($admin))->getJson($this->admin('deliveries') . '?status=0')->assertOk()->json('data');
        $this->assertCount(1, $waiting);
        $this->withHeaders($this->auth($admin))->postJson($this->admin('deliver'), [
            'id' => $waiting[0]['id'], 'data' => ['ip' => '203.0.113.7', 'root' => 's3cret', 'junk' => 'x'], 'note' => 'Ready',
        ])->assertOk();
        $view = $this->withHeaders($this->auth($user))->getJson('/api/v1/user/product/delivery?trade_no=' . $order->trade_no)->json('data.delivery');
        $this->assertSame('delivered', $view['status']);
        $this->assertSame(['ip', 'root'], array_column($view['fields'], 'key'));
        $this->assertSame('Ready', $view['note']);
    }

    public function testUnlimitedContentGoesToEveryBuyer()
    {
        $product = $this->product(['delivery' => 'unlimited', 'name' => 'Guide',
            'fields' => [['key' => 'link', 'label' => 'Download', 'type' => 'link']],
            'content' => ['link' => 'https://example.com/file']]);
        foreach ([$this->makeUser(), $this->makeUser()] as $user) {
            $order = $this->pay($this->buy($user, $product));
            $view = $this->withHeaders($this->auth($user))->getJson('/api/v1/user/product/delivery?trade_no=' . $order->trade_no)->json('data.delivery');
            $this->assertSame('https://example.com/file', $view['fields'][0]['value']);
        }
        $store = $this->withHeaders($this->auth($this->makeUser()))->getJson('/api/v1/user/product/fetch')->json('data');
        $this->assertStringNotContainsString('example.com/file', json_encode($store));
    }

    public function testLimitsAndHiddenProductsAreRefusedBeforeAnyMoneyMoves()
    {
        $product = $this->product(['per_user_limit' => 1]);
        $this->import($product, "a|1|x\nb|2|y");
        $user = $this->makeUser(['balance' => 500000]);
        $this->pay($this->buy($user, $product));
        $this->withHeaders($this->auth($user))->postJson('/api/v1/user/product/order', ['product_id' => $product->id])->assertStatus(422);
        $this->assertSame(250000, (int)DB::table('v2_user')->where('id', $user->id)->value('balance'));

        $hidden = $this->product(['show' => false]);
        $this->withHeaders($this->auth($this->makeUser()))->postJson('/api/v1/user/product/order', ['product_id' => $hidden->id])->assertStatus(404);
    }

    public function testAProductWithSalesIsHiddenNotDeleted()
    {
        $product = $this->product();
        $this->import($product, "a|1|x");
        $this->pay($this->buy($this->makeUser(), $product));
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->withHeaders($this->auth($admin))->postJson($this->admin('drop'), ['id' => $product->id])->assertOk();
        $this->assertNotNull(Product::find($product->id));
        $this->assertFalse((bool)Product::find($product->id)->show);
    }

    public function testAReservedUnitIsDeliveredEvenIfTheModeChangedBeforePayment()
    {
        $product = $this->product();
        $this->import($product, "a@example.com|pw1|2000-01-01");
        $user = $this->makeUser();
        $order = $this->buy($user, $product);
        // The admin switches the product to made-to-order before the payment lands.
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->withHeaders($this->auth($admin))->postJson($this->admin('save'), [
            'id' => $product->id, 'name' => 'US Apple ID', 'price' => 250000, 'delivery' => 'manual',
            'fields' => self::FIELDS, 'show' => true,
        ])->assertOk();
        $order = $this->pay($order);
        $this->assertSame(ProductItem::SOLD, (int)ProductItem::first()->status);
        $view = $this->withHeaders($this->auth($user))->getJson('/api/v1/user/product/delivery?trade_no=' . $order->trade_no)
            ->json('data.delivery');
        $this->assertSame('delivered', $view['status']);
        $this->assertSame('a@example.com', $view['fields'][0]['value']);
    }

    public function testAReservedUnitCannotBeDeletedOrEdited()
    {
        $product = $this->product();
        $this->import($product, "a@example.com|pw1|2000-01-01");
        $this->buy($this->makeUser(), $product);
        $item = ProductItem::first();
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->withHeaders($this->auth($admin))->postJson($this->admin('itemDrop'), ['id' => $item->id])->assertStatus(422);
        $this->withHeaders($this->auth($admin))->postJson($this->admin('itemUpdate'), ['id' => $item->id, 'withdrawn' => true])->assertStatus(422);
        $this->withHeaders($this->auth($admin))->postJson($this->admin('itemDrop'), ['id' => 999999])->assertStatus(404);
        $this->assertSame(ProductItem::RESERVED, (int)ProductItem::find($item->id)->status);
    }

    public function testAProductPurchaseIsNeverSubscriptionCredit()
    {
        $product = $this->product(['delivery' => 'manual', 'name' => 'VPS', 'fields' => [['key' => 'ip', 'label' => 'IP']]]);
        $user = $this->makeUser(['expired_at' => time() + 30 * 86400, 'transfer_enable' => 107374182400]);
        $paid = $this->pay($this->buy($user, $product));
        $this->assertSame(3, (int)$paid->status);
        $service = new OrderService(new Order());
        foreach (['getSurplusValueByPeriod', 'getSurplusValueByOneTime'] as $method) {
            $change = new Order();
            $change->surplus_order_ids = null;
            $call = new \ReflectionMethod(OrderService::class, $method);
            $call->setAccessible(true);
            $call->invoke($service, User::find($user->id), $change);
            $this->assertNotContains((int)$paid->id, (array)$change->surplus_order_ids, $method);
        }
    }

    public function testFieldsAreCleaned()
    {
        $fields = ProductOrderService::normalizeFields([
            ['key' => 'Email Address', 'label' => 'Email'], ['key' => 'email_address', 'label' => 'Dup'],
            ['label' => 'No key'], ['key' => 'x', 'label' => ''], ['key' => 'p', 'label' => 'P', 'type' => 'weird'],
        ]);
        $this->assertSame(['email_address', 'f2', 'p'], array_column($fields, 'key'));
        $this->assertSame('text', $fields[2]['type']);
    }
}
