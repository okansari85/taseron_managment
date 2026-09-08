<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmergencyEquipmentInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'checklist_item_ids' => ['nullable', 'array'],
            'checklist_item_ids.*' => ['integer', 'exists:emergency_equipment_type_checklist_items,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'inspected_at' => ['nullable', 'date'],
            'inspected_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'inspected_by_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'checklist_item_ids.array' => 'Tespit edilen maddeler liste olarak gönderilmelidir.',
            'checklist_item_ids.*.integer' => 'Checklist maddesi geçerli olmalıdır.',
            'checklist_item_ids.*.exists' => 'Seçilen checklist maddelerinden biri bulunamadı.',
            'notes.string' => 'Notlar geçerli bir metin olmalıdır.',
            'notes.max' => 'Notlar en fazla 2000 karakter olabilir.',
            'inspected_at.date' => 'Kontrol tarihi geçerli bir tarih olmalıdır.',
            'inspected_by_user_id.integer' => 'Denetçi geçerli olmalıdır.',
            'inspected_by_user_id.exists' => 'Seçilen denetçi bulunamadı.',
            'inspected_by_name.string' => 'Denetçi adı geçerli bir metin olmalıdır.',
            'inspected_by_name.max' => 'Denetçi adı en fazla 255 karakter olabilir.',
        ];
    }
}
