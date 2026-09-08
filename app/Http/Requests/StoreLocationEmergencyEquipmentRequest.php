<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationEmergencyEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'equipment_type_id' => [
                'required',
                'integer',
                Rule::exists('emergency_equipment_types', 'id')->where('tenant_id', $tenantId),
            ],
            'code' => ['nullable', 'string', 'max:100'],
            'location_note' => ['nullable', 'string', 'max:500'],
            'install_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,inactive,needs_replacement'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'equipment_type_id.required' => 'Ekipman türü seçimi zorunludur.',
            'equipment_type_id.integer' => 'Ekipman türü geçerli olmalıdır.',
            'equipment_type_id.exists' => 'Seçilen ekipman türü bulunamadı.',
            'code.string' => 'Ekipman kodu geçerli bir metin olmalıdır.',
            'code.max' => 'Ekipman kodu en fazla 100 karakter olabilir.',
            'location_note.string' => 'Konum notu geçerli bir metin olmalıdır.',
            'location_note.max' => 'Konum notu en fazla 500 karakter olabilir.',
            'install_date.date' => 'Kurulum tarihi geçerli bir tarih olmalıdır.',
            'status.in' => 'Durum geçersiz.',
            'is_active.boolean' => 'Aktiflik değeri geçerli olmalıdır.',
        ];
    }
}
