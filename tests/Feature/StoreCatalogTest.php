<?php

namespace Tests\Feature;

use App\Services\StoreCatalog;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * One store: the banners and apps the admin "Store" page manages reach both
 * the website (user/appstore/list) and the app (inside its config document).
 */
class StoreCatalogTest extends TestCase
{
    use PanelFixtures;

    private ?string $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $file = StoreCatalog::file();
        $this->saved = is_file($file) ? file_get_contents($file) : null;
        @unlink($file);
    }

    protected function tearDown(): void
    {
        $file = StoreCatalog::file();
        if ($this->saved === null) @unlink($file);
        else file_put_contents($file, $this->saved);
        parent::tearDown();
    }

    private function admin(): array
    {
        $user = $this->makeUser(['is_admin' => 1]);
        $token = 'store-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function user(): array
    {
        $user = $this->makeUser();
        $token = 'store-u-' . bin2hex(random_bytes(16));
        Cache::put($token, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
        return ['authorization' => $token];
    }

    private function route(string $action): string
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\AppStoreController@' . $action) return '/' . $route->uri();
        }
        $this->fail('Missing admin store route ' . $action);
    }

    public function testWhatTheAdminEntersReachesTheWebsiteAndTheApp()
    {
        $before = $this->get('/api/v1/guest/comm/appConfig')->headers->get('ETag');
        $admin = $this->admin();
        $this->withHeaders($admin)->postJson($this->route('saveBanner'), [
            'title' => 'Apple ID', 'color' => '#4f46e5', 'link' => 'product:7', 'image' => 'https://example.com/b.jpg', 'enabled' => true,
        ])->assertOk();
        $this->withHeaders($admin)->postJson($this->route('saveBanner'), ['title' => 'Hidden', 'enabled' => false])->assertOk();
        $this->withHeaders($admin)->postJson($this->route('saveApp'), [
            'category' => 'android', 'name' => 'Doctor Mobile', 'downloadUrl' => 'https://example.com/dm.apk', 'version' => '2.3',
        ])->assertOk();

        $site = $this->withHeaders($this->user())->getJson('/api/v1/user/appstore/list')->assertOk()->json('data');
        $this->assertSame(['Apple ID'], array_column($site['banners'], 'title'));
        $this->assertSame('product:7', $site['banners'][0]['link']);
        $this->assertSame('doctor-mobile-android', $site['categories'][0]['apps'][0]['id']);

        $doc = $this->get('/api/v1/guest/comm/appConfig');
        $this->assertNotSame($before, $doc->headers->get('ETag'), 'a changed store must not be served as the cached document');
        $store = $doc->json('store');
        $this->assertSame([['image' => 'https://example.com/b.jpg', 'title' => 'Apple ID', 'color' => '#4f46e5', 'product' => 7]], $store['banners']);
        $this->assertSame('https://example.com/dm.apk', $store['apps'][0]['url']);
        $this->assertSame(['android'], $store['apps'][0]['platforms']);
    }

    public function testAMovedAppIsListedOnceAndEmptyCategoriesGo()
    {
        $admin = $this->admin();
        $this->withHeaders($admin)->postJson($this->route('saveApp'), ['id' => 'dm', 'category' => 'android', 'name' => 'DM', 'downloadUrl' => 'https://example.com/a'])->assertOk();
        $this->withHeaders($admin)->postJson($this->route('saveApp'), ['id' => 'dm', 'category' => 'windows', 'name' => 'DM', 'downloadUrl' => 'https://example.com/w'])->assertOk();
        $cats = StoreCatalog::load()['categories'];
        $this->assertSame(['windows'], array_column($cats, 'id'));
        $this->withHeaders($admin)->postJson($this->route('deleteApp'), ['category' => 'windows', 'app_id' => 'dm'])->assertOk();
        $this->assertSame([], StoreCatalog::load()['categories']);
    }

    public function testABannerLinkIsAProductACategoryAWebAddressOrAPage()
    {
        $this->assertSame('product:12', StoreCatalog::link('product:12'));
        $this->assertSame('category:apple-id', StoreCatalog::link('category:apple-id'));
        $this->assertSame('https://example.com/x', StoreCatalog::link('https://example.com/x'));
        $this->assertSame('#/dashboard/store', StoreCatalog::link('/dashboard/store'));
        $this->assertNull(StoreCatalog::link('javascript:alert(1)'));
        $this->assertNull(StoreCatalog::link('http://example.com'));
        $admin = $this->admin();
        $this->withHeaders($admin)->postJson($this->route('saveBanner'), ['title' => 'x', 'link' => 'javascript:alert(1)'])->assertStatus(422);
        $this->assertSame([], StoreCatalog::load()['banners']);
    }

    public function testAnEmptyStoreLeavesTheConfigFilesOwnSection()
    {
        // Nothing entered on the Store page: the app document has no store
        // (the shipped file has none either), rather than an empty one.
        $this->assertArrayNotHasKey('store', $this->get('/api/v1/guest/comm/appConfig')->json());
        $this->assertSame([], StoreCatalog::forApp());
    }

    public function testTheSameAppAddedForTwoPlatformsKeepsBoth()
    {
        $admin = $this->admin();
        foreach (['android', 'windows', 'windows'] as $platform) {
            $this->withHeaders($admin)->postJson($this->route('saveApp'), ['category' => $platform, 'name' => 'Hiddify', 'downloadUrl' => 'https://example.com/' . $platform])->assertOk();
        }
        $ids = [];
        foreach (StoreCatalog::load()['categories'] as $cat) {
            foreach ($cat['apps'] as $a) $ids[] = $cat['id'] . '/' . $a['id'];
        }
        sort($ids);
        $this->assertSame(['android/hiddify-android', 'windows/hiddify-windows', 'windows/hiddify-windows-2'], $ids);
    }

    public function testEverythingSwitchedOffIsAnEmptyStoreNotTheOldOne()
    {
        $custom = base_path('resources/rules/custom.dm-app.json');
        $this->assertFileDoesNotExist($custom, 'a custom file would be overwritten by this test');
        file_put_contents($custom, '{"version": 1, "settings": {}, "store": {"banners": [{"image": "https://example.com/old.jpg"}]}}');
        try {
            // Nothing entered on the Store page yet: the file's section applies.
            $this->assertSame('https://example.com/old.jpg', $this->get('/api/v1/guest/comm/appConfig')->json('store.banners.0.image'));
            $this->withHeaders($this->admin())->postJson($this->route('saveBanner'), ['title' => 'Off', 'enabled' => false])->assertOk();
            $this->assertArrayNotHasKey('store', $this->get('/api/v1/guest/comm/appConfig')->json());
        } finally {
            @unlink($custom);
        }
    }

    public function testEverySaveChangesTheDocumentEtagEvenWithinASecond()
    {
        StoreCatalog::save(['banners' => [['id' => 1, 'title' => 'A', 'enabled' => true]], 'categories' => []]);
        $first = $this->get('/api/v1/guest/comm/appConfig')->headers->get('ETag');
        StoreCatalog::save(['banners' => [['id' => 1, 'title' => 'B', 'enabled' => true]], 'categories' => []]);
        $this->assertNotSame($first, $this->get('/api/v1/guest/comm/appConfig')->headers->get('ETag'));
    }
}
