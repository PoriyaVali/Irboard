<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GiftcardGenerate extends FormRequest
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
            'type' => 'required|in:1,2,3,4,5',
            'value' => ['required_if:type,1,2,3,5', 'nullable', 'integer'],
            'plan_id' => ['required_if:type,5', 'nullable','integer'],
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer',
            'limit_use' => 'nullable|integer',
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
            'value.required' => 'مقدار نمی‌تواند خالی باشد',
            'value.integer' => 'مقدار دارای فرمت نامعتبر است',
            'plan_id.required' => '订阅 نمی‌تواند خالی باشد',
            'started_at.required' => 'زمان شروع نمی‌تواند خالی باشد',
            'started_at.integer' => 'زمان شروع دارای فرمت نامعتبر است',
            'ended_at.required' => 'زمان پایان نمی‌تواند خالی باشد',
            'ended_at.integer' => 'زمان پایان دارای فرمت نامعتبر است',
            'limit_use.integer' => 'حداکثر دفعات استفاده دارای فرمت نامعتبر است'
        ];
    }
}
