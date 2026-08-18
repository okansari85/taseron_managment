<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncOrganizationLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_ids' => [
                'required',
                'array',
            ],

            'location_ids.*' => [
                'integer',
                'distinct',
                'exists:locations,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'location_ids.required' => 'Lokasyon listesi zorunludur.',
            'location_ids.array' => 'Lokasyon listesi geçerli bir dizi olmalıdır.',
            'location_ids.*.integer' => 'Lokasyon ID değeri geçerli olmalıdır.',
            'location_ids.*.distinct' => 'Aynı lokasyon birden fazla kez gönderilemez.',
            'location_ids.*.exists' => 'Seçilen lokasyon bulunamadı.',
        ];
    }
}
