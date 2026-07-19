<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServerTrojanSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'show' => '',
            'name' => 'required',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'network' => 'required',
            'network_settings' => 'nullable',
            'allow_insecure' => 'nullable|in:0,1',
            'server_name' => 'nullable',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric'
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'نام نود نمی‌تواند خالی باشد',
            'group_id.required' => 'گروه دسترسی نمی‌تواند خالی باشد',
            'group_id.array' => 'گروه دسترسی دارای فرمت نادرست است',
            'route_id.array' => 'گروه مسیر دارای فرمت نادرست است',
            'parent_id.integer' => 'نود والد دارای فرمت نادرست است',
            'host.required' => 'آدرس نود نمی‌تواند خالی باشد',
            'port.required' => 'پورت اتصال نمی‌تواند خالی باشد',
            'server_port.required' => 'پورت سرویس بک‌اند نمی‌تواند خالی باشد',
            'allow_insecure.in' => '允许不安全 دارای فرمت نادرست است',
            'tags.array' => 'برچسب دارای فرمت نادرست است',
            'rate.required' => 'ضریب نمی‌تواند خالی باشد',
            'rate.numeric' => 'ضریب دارای فرمت نادرست است'
        ];
    }
}
