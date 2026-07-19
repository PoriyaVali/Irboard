<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlanUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'show' => 'in:0,1',
            'renew' => 'in:0,1',
            'carry_over_days' => 'in:0,1'
        ];
    }

    public function messages()
    {
        return [
            'show.in' => 'وضعیت فروش دارای فرمت نادرست است',
            'renew.in' => 'وضعیت تمدید دارای فرمت نادرست است'
        ];
    }
}
