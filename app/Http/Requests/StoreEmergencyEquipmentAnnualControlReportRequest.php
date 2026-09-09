<?php

namespace App\Http\Requests;

use App\Models\EmergencyEquipmentAnnualControlReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmergencyEquipmentAnnualControlReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'control_date' => ['required', 'date'],
            'next_control_date' => ['nullable', 'date'],
            'result' => ['nullable', 'string', Rule::in(EmergencyEquipmentAnnualControlReport::RESULTS)],
            'company_name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'equipment' => ['nullable', 'array'],
            'equipment.*.id' => ['required', 'integer', 'exists:location_emergency_equipment,id'],
            'equipment.*.result' => ['nullable', 'string', Rule::in(EmergencyEquipmentAnnualControlReport::RESULTS)],
            'equipment.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'control_date.required' => 'Kontrol tarihi zorunludur.',
            'control_date.date' => 'Kontrol tarihi geçerli olmalıdır.',
            'next_control_date.date' => 'Sonraki kontrol tarihi geçerli olmalıdır.',
            'file.required' => 'Rapor PDF dosyası zorunludur.',
            'file.mimes' => 'Rapor dosyası PDF formatında olmalıdır.',
            'file.max' => 'Rapor dosyası en fazla 20 MB olabilir.',
            'equipment.*.id.required' => 'Ekipman seçimi zorunludur.',
            'equipment.*.id.exists' => 'Seçilen ekipmanlardan biri bulunamadı.',
        ];
    }
}
