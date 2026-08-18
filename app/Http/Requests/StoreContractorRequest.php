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

            'contractor_type.required' =>
                'Taşeron türü zorunludur.',

            'contractor_type.in' =>
                'Geçersiz taşeron türü.',
        ];
    }
}
