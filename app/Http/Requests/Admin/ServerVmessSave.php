<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServerVmessSave extends FormRequest
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
            'tls' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'network' => 'required|in:tcp,kcp,ws,http,domainsocket,quic,grpc,httpupgrade,xhttp',
            'networkSettings' => 'nullable|array',
            'networkSettings.security' => 'nullable|in:auto,aes-128-gcm,chacha20-poly1305,none',
            'ruleSettings' => 'nullable|array',
            'tlsSettings' => 'nullable|array',
            'dnsSettings' => 'nullable|array'
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'نام نود نمی‌تواند خالی باشد',
            'group_id.required' => 'گروه دسترسی نمی‌تواند خالی باشد',
            'group_id.array' => 'گروه دسترسی دارای فرمت نادرست است',
            'route_id.array' => 'گروه مسیر دارای فرمت نادرست است',
            'parent_id.integer' => '父ID دارای فرمت نادرست است',
            'host.required' => 'آدرس نود نمی‌تواند خالی باشد',
            'port.required' => 'پورت اتصال نمی‌تواند خالی باشد',
            'server_port.required' => 'پورت سرویس بک‌اند نمی‌تواند خالی باشد',
            'tls.required' => 'TLS نمی‌تواند خالی باشد',
            'tags.array' => 'برچسب دارای فرمت نادرست است',
            'rate.required' => 'ضریب نمی‌تواند خالی باشد',
            'rate.numeric' => 'ضریب دارای فرمت نادرست است',
            'network.required' => 'پروتکل انتقال نمی‌تواند خالی باشد',
            'network.in' => 'پروتکل انتقال دارای فرمت نادرست است',
            'networkSettings.array' => 'پروتکل انتقال配置有误',
            'networkSettings.security.in' => 'vmess加密نوع只能是: auto, aes-128-gcm, chacha20-poly1305, none',
            'ruleSettings.array' => '规则配置有误',
            'tlsSettings.array' => 'tls配置有误',
            'dnsSettings.array' => 'dns配置有误'
        ];
    }
}
