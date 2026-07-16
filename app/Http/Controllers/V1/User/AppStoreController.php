<?php
namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppStoreController extends Controller
{
    private function getDataFile()
    {
        return base_path('storage/appstore/apps.json');
    }

    public function list(Request $request)
    {
        $file = $this->getDataFile();
        if (!file_exists($file)) {
            return response(['data' => ['banners' => [], 'categories' => []]]);
        }
        $data = json_decode(file_get_contents($file), true);
        
        // فیلتر بنرهای فعال
        $data['banners'] = array_values(array_filter($data['banners'] ?? [], function($b) {
            return $b['enabled'] ?? false;
        }));
        
        // مرتب‌سازی اپ‌ها بر اساس order
        foreach ($data['categories'] as &$cat) {
            usort($cat['apps'], function($a, $b) {
                return ($a['order'] ?? 99) - ($b['order'] ?? 99);
            });
        }
        
        return response(['data' => $data]);
    }
}
