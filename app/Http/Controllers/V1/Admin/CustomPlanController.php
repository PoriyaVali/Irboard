<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomPlanSettingsService;
use Illuminate\Http\Request;

class CustomPlanController extends Controller
{
    public function fetch()
    {
        return response(['data' => CustomPlanSettingsService::fetch()]);
    }

    private function draft(Request $request): array
    {
        return $request->validate(['configuration' => 'required|array',
            'settings_revision' => 'required|string|size:64|regex:/^[a-f0-9]+$/',
            'source_revision' => 'required|string|size:64|regex:/^[a-f0-9]+$/']);
    }

    public function preview(Request $request)
    {
        $draft = $this->draft($request);
        return response(['data' => CustomPlanSettingsService::preview($draft['configuration'], $draft['settings_revision'], $draft['source_revision'])]);
    }

    public function save(Request $request)
    {
        $draft = $this->draft($request);
        return response(['data' => CustomPlanSettingsService::save($draft['configuration'], $draft['settings_revision'], $draft['source_revision'])]);
    }
}
