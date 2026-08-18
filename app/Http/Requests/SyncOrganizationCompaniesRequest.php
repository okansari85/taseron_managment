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
            'business_entity_ids' => [
                'required',
                'array',
            ],

            'business_entity_ids.*' => [
                'integer',
                'distinct',
                'exists:business_entities,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'business_entity_ids.required' =>
                'Şirket listesi zorunludur.',

            'business_entity_ids.array' =>
                'Şirket listesi geçerli bir dizi olmalıdır.',

            'business_entity_ids.*.integer' =>
                'Business Entity ID değeri geçerli olmalıdır.',

            'business_entity_ids.*.distinct' =>
                'Aynı şirket birden fazla kez gönderilemez.',

            'business_entity_ids.*.exists' =>
                'Seçilen şirket bulunamadı.',
        ];
    }
}
