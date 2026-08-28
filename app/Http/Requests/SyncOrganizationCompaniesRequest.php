<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncOrganizationCompaniesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_ids' => ['required', 'array'],
            'company_ids.*' => ['integer', 'distinct', 'exists:companies,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_ids.required' => 'Şirket listesi zorunludur.',
            'company_ids.array' => 'Şirket listesi geçerli bir dizi olmalıdır.',
            'company_ids.*.integer' => 'Şirket ID değeri geçerli bir tam sayı olmalıdır.',
            'company_ids.*.distinct' => 'Aynı şirket birden fazla kez gönderilemez.',
            'company_ids.*.exists' => 'Seçilen şirket bulunamadı.',
        ];
    }
}
