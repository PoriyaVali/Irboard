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
            'speed_limit' => 'nullable|integer',
            'show' => 'in:0,1',
            'renew' => 'in:0,1',
            'carry_over_days' => 'in:0,1'
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'نام بسته نمی‌تواند خالی باشد',
            'type.required' => 'نوع بسته نمی‌تواند خالی باشد',
            'type.in' => 'نوع بسته دارای فرمت نامعتبر است',
            'group_id.required' => 'گروه دسترسی نمی‌تواند خالی باشد',
            'transfer_enable.required' => 'ترافیک نمی‌تواند خالی باشد',
            'device_limit.integer' => 'محدودیت تعداد دستگاه دارای فرمت نامعتبر است',
            'month_price.integer' => 'مبلغ ماهانه دارای فرمت نامعتبر است',
            'quarter_price.integer' => 'مبلغ فصلی دارای فرمت نامعتبر است',
            'half_year_price.integer' => 'مبلغ شش‌ماهه دارای فرمت نامعتبر است',
            'year_price.integer' => 'مبلغ سالانه دارای فرمت نامعتبر است',
            'two_year_price.integer' => 'مبلغ دوساله دارای فرمت نامعتبر است',
            'three_year_price.integer' => 'مبلغ سه‌ساله دارای فرمت نامعتبر است',
            'onetime_price.integer' => 'مبلغ یک‌باره有误',
            'reset_price.integer' => 'مبلغ بسته‌ی بازنشانی ترافیک有误',
            'month_price_usd.numeric' => 'قیمت دلاری ماهانه نامعتبر است',
            'quarter_price_usd.numeric' => 'قیمت دلاری سه ماهه نامعتبر است',
            'half_year_price_usd.numeric' => 'قیمت دلاری شش ماهه نامعتبر است',
            'year_price_usd.numeric' => 'قیمت دلاری سالانه نامعتبر است',
            'two_year_price_usd.numeric' => 'قیمت دلاری دو ساله نامعتبر است',
            'three_year_price_usd.numeric' => 'قیمت دلاری سه ساله نامعتبر است',
            'onetime_price_usd.numeric' => 'قیمت دلاری یکباره نامعتبر است',
            'reset_price_usd.numeric' => 'قیمت دلاری ریست نامعتبر است',
            'reset_traffic_method.integer' => 'روش بازنشانی ترافیک دارای فرمت نامعتبر است',
            'reset_traffic_method.in' => 'روش بازنشانی ترافیک دارای فرمت نامعتبر است',
            'capacity_limit.integer' => 'محدودیت ظرفیت کاربر دارای فرمت نامعتبر است',
            'speed_limit.integer' => 'محدودیت سرعت دارای فرمت نامعتبر است'
        ];
    }
}
