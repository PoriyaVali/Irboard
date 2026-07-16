<?php
namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppStoreController extends Controller
{
    private function getDataFile()
    {
        return base_path('storage/appstore/apps.json');
    }

    private function loadData()
    {
        $file = $this->getDataFile();
        if (!file_exists($file)) return ['banners' => [], 'categories' => []];
        return json_decode(file_get_contents($file), true) ?: ['banners' => [], 'categories' => []];
    }

    private function saveData($data)
    {
        $file = $this->getDataFile();
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function list(Request $request)
    {
        return response(['data' => $this->loadData()]);
    }

    public function saveApp(Request $request)
    {
        $data = $this->loadData();
        $app = $request->only(['id', 'name', 'description', 'icon', 'version', 'size', 'downloadUrl', 'order']);
        $catId = $request->input('category');

        if (empty($app['id']) || empty($app['name']) || empty($catId)) {
            abort(500, 'فیلدهای ضروری را پر کنید');
        }

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

        $this->saveData($data);
        return response(['data' => true]);
    }

    public function saveBanner(Request $request)
    {
        $data = $this->loadData();
        $banner = $request->only(['id', 'title', 'description', 'badge', 'gradient', 'color', 'link', 'enabled']);
        $banner['enabled'] = (bool)($banner['enabled'] ?? true);

        if (empty($banner['title'])) abort(500, 'عنوان بنر الزامی است');

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
}
