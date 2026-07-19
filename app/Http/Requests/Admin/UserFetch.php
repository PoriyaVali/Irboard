<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserFetch extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'filter.*.key' => 'required|in:id,email,transfer_enable,device_limit,d,expired_at,uuid,token,invite_by_email,invite_user_id,plan_id,banned,remarks,is_admin',
            'filter.*.condition' => 'required|in:>,<,=,>=,<=,模糊,!=,تقریبی',
            'filter.*.value' => 'required'
        ];
    }

    public function messages()
    {
        return [
            'filter.*.key.required' => 'کلید فیلتر نمی‌تواند خالی باشد',
            'filter.*.key.in' => 'کلید فیلترپارامتر نامعتبر است',
            'filter.*.condition.required' => 'شرط فیلتر نمی‌تواند خالی باشد',
            'filter.*.condition.in' => 'شرط فیلترپارامتر نامعتبر است',
            'filter.*.value.required' => 'مقدار فیلتر نمی‌تواند خالی باشد'
        ];
    }
}
