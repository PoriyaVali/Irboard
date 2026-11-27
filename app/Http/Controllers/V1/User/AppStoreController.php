<?php
namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\StoreCatalog;
use Illuminate\Http\Request;

/** The store's banners and apps for the website; see StoreCatalog. */
class AppStoreController extends Controller
{
    public function list(Request $request)
    {
        return response(['data' => StoreCatalog::forUsers()]);
    }
}
