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
            'code' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'string', 'max:100'],
            'brand_ids' => ['sometimes', 'array'],
            'brand_ids.*' => ['integer', 'exists:brands,id'],
            'operational_region_id' => ['nullable', 'integer', 'exists:operational_regions,id'],
            'activity' => ['nullable', 'string', 'max:255'],
            'sub_activity' => ['nullable', 'string', 'max:255'],
            'nace_code' => ['nullable', 'string', 'max:50'],
            'hazard_class' => ['required', 'string', 'max:100'],
            'sgk_workplace_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'photos' => ['sometimes', 'array'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_entity_id.required' => 'Business Entity seçimi zorunludur.',
            'business_entity_id.integer' => 'Business Entity ID geçerli olmalıdır.',
            'business_entity_id.exists' => 'Seçilen Business Entity bulunamadı.',
            'brand_ids.array' => 'Markalar liste olarak gönderilmelidir.',
            'brand_ids.*.integer' => 'Marka ID geçerli olmalıdır.',
            'brand_ids.*.exists' => 'Seçilen markalardan biri bulunamadı.',
            'operational_region_id.integer' => 'Operasyonel alan ID geçerli olmalıdır.',
            'operational_region_id.exists' => 'Seçilen operasyonel alan bulunamadı.',
            'activity.string' => 'Faaliyet geçerli bir metin olmalıdır.',
            'activity.max' => 'Faaliyet en fazla 255 karakter olabilir.',
            'sub_activity.string' => 'Alt faaliyet geçerli bir metin olmalıdır.',
            'sub_activity.max' => 'Alt faaliyet en fazla 255 karakter olabilir.',
            'nace_code.string' => 'NACE kodu geçerli bir metin olmalıdır.',
            'nace_code.max' => 'NACE kodu en fazla 50 karakter olabilir.',
            'hazard_class.required' => 'Tehlike sınıfı zorunludur.',
            'hazard_class.string' => 'Tehlike sınıfı geçerli bir metin olmalıdır.',
            'hazard_class.max' => 'Tehlike sınıfı en fazla 100 karakter olabilir.',
            'sgk_workplace_number.string' => 'SGK işyeri numarası geçerli bir metin olmalıdır.',
            'sgk_workplace_number.max' => 'SGK işyeri numarası en fazla 50 karakter olabilir.',
            'photos.array' => 'Şube fotoğrafları liste olarak gönderilmelidir.',
            'photos.*.file' => 'Şube fotoğrafı geçerli bir dosya olmalıdır.',
            'photos.*.image' => 'Şube fotoğrafı bir görsel dosyası olmalıdır.',
            'photos.*.mimes' => 'Şube fotoğrafı jpg, jpeg, png veya webp formatında olmalıdır.',
            'photos.*.max' => 'Şube fotoğrafı en fazla 4 MB olabilir.',
        ];
    }
}
