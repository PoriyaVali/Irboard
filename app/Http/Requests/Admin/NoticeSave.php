<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NoticeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title' => 'required',
            'content' => 'required',
            'img_url' => 'nullable|url',
            'tags' => 'nullable|array'
        ];
    }

    public function messages()
    {
        return [
            'title.required' => 'عنوان نمی‌تواند خالی باشد',
            'content.required' => '内容 نمی‌تواند خالی باشد',
            'img_url.url' => 'آدرس تصویر دارای فرمت نادرست است',
            'tags.array' => 'برچسب دارای فرمت نادرست است'
        ];
    }
}
