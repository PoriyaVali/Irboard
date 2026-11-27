<?php
namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\StoreCatalog;
use Illuminate\Http\Request;

/**
 * The store's banners and apps, edited on the admin "Store" page next to the
 * digital products. Stored by StoreCatalog; the website and the app both read
 * them from there.
 */
class AppStoreController extends Controller
{
    private function loadData()
    {
        return StoreCatalog::load();
    }

    private function saveData($data)
    {
        StoreCatalog::save($data);
    }

    public function list(Request $request)
    {
        return response(['data' => $this->loadData()]);
    }

    public function saveApp(Request $request)
    {
        $data = $this->loadData();
        $app = $request->only(['id', 'name', 'description', 'icon', 'version', 'size', 'downloadUrl', 'order']);
        $catId = trim((string)$request->input('category', ''));
        $app['name'] = trim((string)($app['name'] ?? ''));
        // A new app (the form sends no id) gets an id of its own, never one
        // already in use: the same app added for a second platform is a
        // second entry, not a move of the first.
        $isNew = empty($app['id']);
        if ($isNew) {
            $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($app['name'] . '-' . $catId)), '-') ?: 'app';
            $taken = [];
            foreach ($data['categories'] as $cat) {
                foreach ($cat['apps'] ?? [] as $a) $taken[(string)($a['id'] ?? '')] = true;
            }
            $app['id'] = $base;
            for ($n = 2; isset($taken[$app['id']]); $n++) $app['id'] = $base . '-' . $n;
        }

        if ($app['name'] === '' || $catId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $catId)) {
            abort(422, 'نام و دستهٔ برنامه را وارد کنید');
        }
        if (!empty($app['downloadUrl']) && !preg_match('#^https?://\S+$#i', $app['downloadUrl'])) {
            abort(422, 'نشانی دانلود معتبر نیست');
        }

        // An edited app moved to another category: out of the old one, so it
        // is not listed twice.
        foreach ($isNew ? [] : $data['categories'] as $i => $cat) {
            if (($cat['id'] ?? null) === $catId) continue;
            $data['categories'][$i]['apps'] = array_values(array_filter($cat['apps'] ?? [], fn ($a) => ($a['id'] ?? null) !== $app['id']));
        }
        $data['categories'] = self::withoutEmpty($data['categories']);

        // پیدا کردن یا ساخت دسته
        $catIndex = -1;
        foreach ($data['categories'] as $i => $cat) {
            if ($cat['id'] === $catId) { $catIndex = $i; break; }
        }
        if ($catIndex === -1) {
            $icons = ['android' => '🤖', 'windows' => '💻', 'ios' => '🍎', 'macos' => '🖥️', 'linux' => '🐧'];
            $data['categories'][] = [
                'id' => $catId,
                'title' => $request->input('category_title', $catId),
                'icon' => $icons[$catId] ?? '📦',
                'apps' => []
            ];
            $catIndex = count($data['categories']) - 1;
        }

        // آپدیت یا اضافه اپ
        $found = false;
        foreach ($data['categories'][$catIndex]['apps'] as $j => $existing) {
            if ($existing['id'] === $app['id']) {
                $data['categories'][$catIndex]['apps'][$j] = $app;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $app['order'] = $app['order'] ?? (count($data['categories'][$catIndex]['apps']) + 1);
            $data['categories'][$catIndex]['apps'][] = $app;
        }

        $this->saveData($data);
        return response(['data' => true]);
    }

    public function deleteApp(Request $request)
    {
        $data = $this->loadData();
        $appId = $request->input('app_id');
        $catId = $request->input('category');

        foreach ($data['categories'] as $i => $cat) {
            if ($cat['id'] === $catId) {
                $data['categories'][$i]['apps'] = array_values(array_filter($cat['apps'], function($a) use ($appId) {
                    return $a['id'] !== $appId;
                }));
                break;
            }
        }
        // An empty category would be an empty tab in the store.
        $data['categories'] = self::withoutEmpty($data['categories']);

        $this->saveData($data);
        return response(['data' => true]);
    }

    public function saveBanner(Request $request)
    {
        $data = $this->loadData();
        $banner = StoreCatalog::banner($request->only(['id', 'title', 'description', 'badge', 'gradient', 'color', 'image', 'link', 'enabled']));

        if (empty($banner['title']) && empty($banner['image'])) abort(422, 'عنوان یا تصویر بنر را وارد کنید');
        if ($request->filled('link') && !isset($banner['link'])) abort(422, 'لینک بنر معتبر نیست');

        if (empty($banner['id'])) {
            $banner['id'] = time();
            $data['banners'][] = $banner;
        } else {
            foreach ($data['banners'] as $i => $b) {
                if ($b['id'] == $banner['id']) {
                    $data['banners'][$i] = $banner;
                    break;
                }
            }
        }

        $this->saveData($data);
        return response(['data' => true]);
    }

    public function deleteBanner(Request $request)
    {
        $data = $this->loadData();
        $id = $request->input('id');
        $data['banners'] = array_values(array_filter($data['banners'], function($b) use ($id) {
            return $b['id'] != $id;
        }));
        $this->saveData($data);
        return response(['data' => true]);
    }

    private static function withoutEmpty(array $categories): array
    {
        return array_values(array_filter($categories, fn ($c) => !empty($c['apps'])));
    }
}
