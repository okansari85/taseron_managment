<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
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

            'company_type' => [
                'required',
                Rule::in([
                    'individual',
                    'corporate',
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Şirket adı zorunludur.',
            'name.string' => 'Şirket adı geçerli bir metin olmalıdır.',
            'name.max' => 'Şirket adı en fazla 255 karakter olabilir.',

            'company_type.required' => 'Şirket türü zorunludur.',
            'company_type.in' => 'Geçersiz şirket türü.',
        ];
    }
}