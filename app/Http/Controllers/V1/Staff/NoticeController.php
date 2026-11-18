<?php

namespace App\Http\Controllers\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\Request;

/**
 * A staff member reads the panel's notices. Changing them is the admin's:
 * a notice reaches every user of the panel.
 */
class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        return response([
            'data' => Notice::orderBy('id', 'DESC')->get()
        ]);
    }
}
