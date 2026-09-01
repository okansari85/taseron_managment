<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractorRequest extends FormRequest
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

            'short_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'logo' => [
                'nullable',
                'image',
                'max:5120',
            ],

            'contractor_type' => [
                'required',
                Rule::in([
                    'permanent',
                    'temporary',
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' =>
                'Taşeron firma adı zorunludur.',

            'name.string' =>
                'Taşeron firma adı geçerli bir metin olmalıdır.',

            'name.max' =>
                'Taşeron firma adı en fazla 255 karakter olabilir.',

            'short_name.string' =>
                'Kısa ad geçerli bir metin olmalıdır.',

            'short_name.max' =>
                'Kısa ad en fazla 255 karakter olabilir.',

            'logo.image' =>
                'Logo geçerli bir görsel dosyası olmalıdır.',

            'logo.max' =>
                'Logo en fazla 5 MB olabilir.',

            'contractor_type.required' =>
                'Taşeron türü zorunludur.',

            'contractor_type.in' =>
                'Geçersiz taşeron türü.',
        ];
    }
}
