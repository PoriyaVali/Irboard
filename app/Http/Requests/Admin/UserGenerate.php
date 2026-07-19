<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserGenerate extends FormRequest
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
            'expired_at' => 'nullable|integer',
            'plan_id' => 'nullable|integer',
            'transfer_enable' => 'nullable|numeric',
            'email_prefix' => 'nullable',
            'email_suffix' => 'required',
            'password' => 'nullable'
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => 'تعداد تولید باید عددی باشد',
            'generate_count.max' => 'تعداد تولید最大为500个'
        ];
    }
}
