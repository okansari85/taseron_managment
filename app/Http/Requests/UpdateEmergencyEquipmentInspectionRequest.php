<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmergencyEquipmentInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer', 'exists:emergency_equipment_inspection_items,id'],
            'items.*.checklist_item_id' => ['required', 'integer', 'exists:emergency_equipment_type_checklist_items,id'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'items.*.photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'items.*.remove_photo' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'inspected_at' => ['nullable', 'date'],
            'remove_photo_ids' => ['nullable', 'array'],
            'remove_photo_ids.*' => ['integer', 'exists:emergency_equipment_inspection_photos,id'],
            'photos' => ['sometimes', 'array'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.array' => 'Tespit edilen maddeler liste olarak gönderilmelidir.',
            'items.*.checklist_item_id.integer' => 'Checklist maddesi geçerli olmalıdır.',
            'items.*.checklist_item_id.exists' => 'Seçilen checklist maddelerinden biri bulunamadı.',
            'items.*.note.max' => 'Madde notu en fazla 500 karakter olabilir.',
            'notes.string' => 'Notlar geçerli bir metin olmalıdır.',
            'notes.max' => 'Notlar en fazla 2000 karakter olabilir.',
            'inspected_at.date' => 'Kontrol tarihi geçerli bir tarih olmalıdır.',
        ];
    }
}
