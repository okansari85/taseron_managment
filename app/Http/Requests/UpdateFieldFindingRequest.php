<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFieldFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'in:yangin_guvenligi,acil_cikis,yangin_kapisi,kacis_yolu,diger'],
            'location_note' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'severity' => ['sometimes', 'in:dusuk,orta,yuksek,kritik'],
            'status' => ['sometimes', 'in:open,closed'],
            'photos' => ['sometimes', 'array'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remove_photo_ids' => ['sometimes', 'array'],
            'remove_photo_ids.*' => ['integer', 'exists:field_finding_photos,id'],
        ];
    }
}
