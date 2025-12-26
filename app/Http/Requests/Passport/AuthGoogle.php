<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthGoogle extends FormRequest
{
    /**
     * قوانین اعتبارسنجی
     * فقط code از گوگل نیاز است (می‌تونه در GET یا POST باشه)
     */
    public function rules()
    {
        return [
            'code' => 'required|string',
        ];
    }

    /**
     * پیام‌های خطا
     */
    public function messages()
    {
        return [
            'code.required' => 'Google authorization code is required',
        ];
    }
}
