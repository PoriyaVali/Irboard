<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MailSend extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'type' => 'required|in:1,2,3,4',
            'subject' => 'required',
            'content' => 'required',
            'receiver' => 'array'
        ];
    }

    public function messages()
    {
        return [
            'type.required' => 'نوع ارسال نمی‌تواند خالی باشد',
            'type.in' => 'نوع ارسال دارای فرمت نامعتبر است',
            'subject.required' => '主题 نمی‌تواند خالی باشد',
            'content.required' => '内容 نمی‌تواند خالی باشد',
            'receiver.array' => 'گیرنده دارای فرمت نامعتبر است'
        ];
    }
}
