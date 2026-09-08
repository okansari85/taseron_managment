<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmergencyEquipmentTypeTipOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Tip adı zorunludur.',
            'label.max' => 'Tip adı en fazla 100 karakter olabilir.',
            'sort_order.integer' => 'Sıra değeri tam sayı olmalıdır.',
            'sort_order.min' => 'Sıra değeri 0 veya daha büyük olmalıdır.',
        ];
    }
}
