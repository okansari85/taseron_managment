<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
            'company_type' => [
                'required',
                Rule::in(['individual', 'corporate']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Şirket adı zorunludur.',
            'name.string' => 'Şirket adı geçerli bir metin olmalıdır.',
            'name.max' => 'Şirket adı en fazla 255 karakter olabilir.',
            'short_name.string' => 'Kısa ad geçerli bir metin olmalıdır.',
            'short_name.max' => 'Kısa ad en fazla 255 karakter olabilir.',
            'description.string' => 'Açıklama geçerli bir metin olmalıdır.',
            'description.max' => 'Açıklama en fazla 500 karakter olabilir.',
            'is_active.required' => 'Durum zorunludur.',
            'is_active.boolean' => 'Durum geçerli olmalıdır.',
            'company_type.required' => 'Şirket türü zorunludur.',
            'company_type.in' => 'Geçersiz şirket türü.',
        ];
    }
}
