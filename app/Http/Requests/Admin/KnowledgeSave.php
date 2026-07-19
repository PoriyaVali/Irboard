<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'category' => 'required',
            'language' => 'required',
            'title' => 'required',
            'body' => 'required'
        ];
    }

    public function messages()
    {
        return [
            'title.required' => 'عنوان نمی‌تواند خالی باشد',
            'category.required' => 'دسته نمی‌تواند خالی باشد',
            'body.required' => '内容 نمی‌تواند خالی باشد',
            'language.required' => '语言 نمی‌تواند خالی باشد'
        ];
    }
}
