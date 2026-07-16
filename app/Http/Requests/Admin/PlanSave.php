<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlanSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name' => 'required',
            'content' => '',
            'group_id' => 'required',
            'transfer_enable' => 'required',
            'device_limit' => 'nullable|integer',
            'month_price' => 'nullable|integer',
            'quarter_price' => 'nullable|integer',
            'half_year_price' => 'nullable|integer',
            'year_price' => 'nullable|integer',
            'two_year_price' => 'nullable|integer',
            'three_year_price' => 'nullable|integer',
            'onetime_price' => 'nullable|integer',
            'reset_price' => 'nullable|integer',
            // فیلدهای قیمت دلاری
            'month_price_usd' => 'nullable|numeric',
            'quarter_price_usd' => 'nullable|numeric',
            'half_year_price_usd' => 'nullable|numeric',
            'year_price_usd' => 'nullable|numeric',
            'two_year_price_usd' => 'nullable|numeric',
            'three_year_price_usd' => 'nullable|numeric',
            'onetime_price_usd' => 'nullable|numeric',
            'reset_price_usd' => 'nullable|numeric',
            'reset_traffic_method' => 'nullable|integer|in:0,1,2,3,4',
            'capacity_limit' => 'nullable|integer',
            'speed_limit' => 'nullable|integer'
        ];
    }

    public function messages()
    {
        return [
            'name.required' => '套餐名称不能为空',
            'type.required' => '套餐类型不能为空',
            'type.in' => '套餐类型格式有误',
            'group_id.required' => '权限组不能为空',
            'transfer_enable.required' => '流量不能为空',
            'device_limit.integer' => '设备数限制格式有误',
            'month_price.integer' => '月付金额格式有误',
            'quarter_price.integer' => '季付金额格式有误',
            'half_year_price.integer' => '半年付金额格式有误',
            'year_price.integer' => '年付金额格式有误',
            'two_year_price.integer' => '两年付金额格式有误',
            'three_year_price.integer' => '三年付金额格式有误',
            'onetime_price.integer' => '一次性金额有误',
            'reset_price.integer' => '流量重置包金额有误',
            'month_price_usd.numeric' => 'قیمت دلاری ماهانه نامعتبر است',
            'quarter_price_usd.numeric' => 'قیمت دلاری سه ماهه نامعتبر است',
            'half_year_price_usd.numeric' => 'قیمت دلاری شش ماهه نامعتبر است',
            'year_price_usd.numeric' => 'قیمت دلاری سالانه نامعتبر است',
            'two_year_price_usd.numeric' => 'قیمت دلاری دو ساله نامعتبر است',
            'three_year_price_usd.numeric' => 'قیمت دلاری سه ساله نامعتبر است',
            'onetime_price_usd.numeric' => 'قیمت دلاری یکباره نامعتبر است',
            'reset_price_usd.numeric' => 'قیمت دلاری ریست نامعتبر است',
            'reset_traffic_method.integer' => '流量重置方式格式有误',
            'reset_traffic_method.in' => '流量重置方式格式有误',
            'capacity_limit.integer' => '容纳用户量限制格式有误',
            'speed_limit.integer' => '限速格式有误'
        ];
    }
}
