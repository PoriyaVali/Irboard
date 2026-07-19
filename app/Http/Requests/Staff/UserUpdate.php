<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email' => 'required|email:strict',
            'password' => 'nullable',
            'transfer_enable' => 'numeric',
            'device_limit' => 'nullable|integer',
            'expired_at' => 'nullable|integer',
            'banned' => 'required|in:0,1',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'u' => 'integer',
            'd' => 'integer',
            'balance' => 'integer',
            'commission_balance' => 'integer'
        ];
    }

    public function messages()
    {
        return [
            'email.required' => 'ایمیل نمی‌تواند خالی باشد',
            'email.email' => 'ایمیل دارای فرمت نادرست است',
            'transfer_enable.numeric' => 'ترافیک دارای فرمت نادرست است',
            'device_limit.integer' => 'محدودیت تعداد دستگاه دارای فرمت نادرست است',
            'expired_at.integer' => '到期时间 دارای فرمت نادرست است',
            'banned.required' => 'وضعیت مسدودسازی نمی‌تواند خالی باشد',
            'banned.in' => 'وضعیت مسدودسازی دارای فرمت نادرست است',
            'plan_id.integer' => 'پلن اشتراک دارای فرمت نادرست است',
            'commission_rate.integer' => 'درصد پورسانت معرفی دارای فرمت نادرست است',
            'commission_rate.nullable' => 'درصد پورسانت معرفی دارای فرمت نادرست است',
            'commission_rate.min' => 'درصد پورسانت معرفی最小为0',
            'commission_rate.max' => 'درصد پورسانت معرفی最大为100',
            'discount.integer' => 'درصد تخفیف اختصاصی دارای فرمت نادرست است',
            'discount.nullable' => 'درصد تخفیف اختصاصی دارای فرمت نادرست است',
            'discount.min' => 'درصد تخفیف اختصاصی最小为0',
            'discount.max' => 'درصد تخفیف اختصاصی最大为100',
            'u.integer' => 'ترافیک آپلود دارای فرمت نادرست است',
            'd.integer' => 'ترافیک دانلود دارای فرمت نادرست است',
            'balance.integer' => 'موجودی دارای فرمت نادرست است',
            'commission_balance.integer' => '佣金 دارای فرمت نادرست است'
        ];
    }
}
