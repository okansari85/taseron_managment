<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmergencyEquipmentTypeChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Checklist maddesi zorunludur.',
            'label.max' => 'Checklist maddesi en fazla 255 karakter olabilir.',
            'sort_order.integer' => 'Sıra numarası tam sayı olmalıdır.',
            'is_active.boolean' => 'Aktif durumu geçerli bir boolean değer olmalıdır.',
        ];
    }
}
