<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CouponGenerate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|integer|max:500',
            'name' => 'required',
            'type' => 'required|in:1,2',
            'value' => 'required|integer',
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer',
            'limit_use' => 'nullable|integer',
            'limit_use_with_user' => 'nullable|integer',
            'limit_plan_ids' => 'nullable|array',
            'limit_period' => 'nullable|array',
            'code' => ''
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => 'تعداد تولید باید عددی باشد',
            'generate_count.max' => 'تعداد تولید最大为500个',
            'name.required' => 'نام نمی‌تواند خالی باشد',
            'type.required' => 'نوع نمی‌تواند خالی باشد',
            'type.in' => 'نوع دارای فرمت نامعتبر است',
            'value.required' => 'مبلغ یا درصد نمی‌تواند خالی باشد',
            'value.integer' => 'مبلغ یا درصد دارای فرمت نامعتبر است',
            'started_at.required' => 'زمان شروع نمی‌تواند خالی باشد',
            'started_at.integer' => 'زمان شروع دارای فرمت نامعتبر است',
            'ended_at.required' => 'زمان پایان نمی‌تواند خالی باشد',
            'ended_at.integer' => 'زمان پایان دارای فرمت نامعتبر است',
            'limit_use.integer' => 'حداکثر دفعات استفاده دارای فرمت نامعتبر است',
            'limit_use_with_user.integer' => 'محدودیت دفعات استفاده‌ی کاربر دارای فرمت نامعتبر است',
            'limit_plan_ids.array' => 'اشتراک مشخص دارای فرمت نامعتبر است',
            'limit_period.array' => 'دوره‌ی مشخص دارای فرمت نامعتبر است'
        ];
    }
}
