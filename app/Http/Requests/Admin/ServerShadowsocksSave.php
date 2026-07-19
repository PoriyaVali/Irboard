<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServerShadowsocksSave extends FormRequest
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
            'parent_id' => 'nullable|integer',
            'route_id' => 'nullable|array',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'cipher' => 'required|in:aes-128-gcm,aes-192-gcm,aes-256-gcm,chacha20-ietf-poly1305,2022-blake3-aes-128-gcm,2022-blake3-aes-256-gcm',
            'obfs' => 'nullable|in:http',
            'obfs_settings' => 'nullable|array',
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
            'cipher.required' => 'روش رمزنگاری نمی‌تواند خالی باشد',
            'tags.array' => 'برچسب دارای فرمت نادرست است',
            'rate.required' => 'ضریب نمی‌تواند خالی باشد',
            'rate.numeric' => 'ضریب دارای فرمت نادرست است',
            'obfs.in' => '混淆 دارای فرمت نادرست است',
            'obfs_settings.array' => 'تنظیمات مبهم‌سازی دارای فرمت نادرست است'
        ];
    }
}
