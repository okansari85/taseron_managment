<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationBusinessEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_entity_id' => ['required', 'integer', 'exists:business_entities,id'],
            'operational_region_id' => ['nullable', 'integer', 'exists:operational_regions,id'],
            'nace_code' => ['required', 'string', 'max:50'],
            'hazard_class' => ['required', 'string', 'max:100'],
            'sgk_workplace_number' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_entity_id.required' => 'Business Entity seçimi zorunludur.',
            'business_entity_id.integer' => 'Business Entity ID geçerli olmalıdır.',
            'business_entity_id.exists' => 'Seçilen Business Entity bulunamadı.',
            'operational_region_id.integer' => 'Operasyonel alan ID geçerli olmalıdır.',
            'operational_region_id.exists' => 'Seçilen operasyonel alan bulunamadı.',
            'nace_code.required' => 'NACE kodu zorunludur.',
            'nace_code.string' => 'NACE kodu geçerli bir metin olmalıdır.',
            'nace_code.max' => 'NACE kodu en fazla 50 karakter olabilir.',
            'hazard_class.required' => 'Tehlike sınıfı zorunludur.',
            'hazard_class.string' => 'Tehlike sınıfı geçerli bir metin olmalıdır.',
            'hazard_class.max' => 'Tehlike sınıfı en fazla 100 karakter olabilir.',
            'sgk_workplace_number.string' => 'SGK işyeri numarası geçerli bir metin olmalıdır.',
            'sgk_workplace_number.max' => 'SGK işyeri numarası en fazla 50 karakter olabilir.',
        ];
    }
}
