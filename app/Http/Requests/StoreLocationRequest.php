<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:5120'],
            'address' => ['nullable', 'string'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Lokasyon adı zorunludur.',
            'name.string' => 'Lokasyon adı geçerli bir metin olmalıdır.',
            'name.max' => 'Lokasyon adı en fazla 255 karakter olabilir.',
            'image.image' => 'Lokasyon resmi geçerli bir görsel olmalıdır.',
            'image.max' => 'Lokasyon resmi en fazla 5 MB olabilir.',
            'city_id.exists' => 'Seçilen il bulunamadı.',
            'district_id.exists' => 'Seçilen ilçe bulunamadı.',
        ];
    }
}
