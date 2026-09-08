<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFieldFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'in:yangin_guvenligi,acil_cikis,yangin_kapisi,kacis_yolu,diger'],
            'location_note' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'severity' => ['required', 'in:dusuk,orta,yuksek,kritik'],
            'photos' => ['sometimes', 'array'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required' => 'Bulgu türü zorunludur.',
            'category.in' => 'Geçersiz bulgu türü.',
            'severity.required' => 'Önem derecesi zorunludur.',
            'severity.in' => 'Geçersiz önem derecesi.',
            'photos.*.image' => 'Fotoğraf geçerli bir görsel olmalıdır.',
            'photos.*.max' => 'Fotoğraf en fazla 8 MB olabilir.',
        ];
    }
}
