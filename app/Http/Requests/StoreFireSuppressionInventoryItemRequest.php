<?php

namespace App\Http\Requests;

use App\Models\FireSuppressionInventoryItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFireSuppressionInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(FireSuppressionInventoryItem::CATEGORIES)],
            'code' => ['nullable', 'string', 'max:100'],
            'location_note' => ['nullable', 'string', 'max:500'],
            'brand' => ['nullable', 'string', 'max:150'],
            'model' => ['nullable', 'string', 'max:150'],
            'serial_no' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'last_control_date' => ['nullable', 'date'],
            'next_control_date' => ['nullable', 'date'],
            'compliance_status' => ['nullable', 'string', Rule::in(FireSuppressionInventoryItem::COMPLIANCE_STATUSES)],
            'open_nonconformity_count' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required' => 'Kategori seçimi zorunludur.',
            'category.in' => 'Geçersiz kategori.',
            'code.string' => 'Ekipman kodu geçerli bir metin olmalıdır.',
            'code.max' => 'Ekipman kodu en fazla 100 karakter olabilir.',
            'location_note.string' => 'Konum notu geçerli bir metin olmalıdır.',
            'location_note.max' => 'Konum notu en fazla 500 karakter olabilir.',
            'last_control_date.date' => 'Son kontrol tarihi geçerli bir tarih olmalıdır.',
            'next_control_date.date' => 'Sonraki kontrol tarihi geçerli bir tarih olmalıdır.',
            'compliance_status.in' => 'Geçersiz uygunluk durumu.',
            'open_nonconformity_count.integer' => 'Uygunsuzluk sayısı geçerli olmalıdır.',
            'open_nonconformity_count.min' => 'Uygunsuzluk sayısı negatif olamaz.',
            'notes.max' => 'Notlar en fazla 2000 karakter olabilir.',
        ];
    }
}
