<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Lokasyon adı zorunludur.',
            'name.string' => 'Lokasyon adı geçerli bir metin olmalıdır.',
            'name.max' => 'Lokasyon adı en fazla 255 karakter olabilir.',
        ];
    }
}