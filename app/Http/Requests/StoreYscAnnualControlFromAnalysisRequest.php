<?php

namespace App\Http\Requests;

use App\Models\EmergencyEquipmentAnnualControlReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreYscAnnualControlFromAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // equipment/control_items, StoreFireSuppressionReportRequest ile AYNI
    // sebeple (yüzlerce satırı ayrı form field'ı yapmak max_input_vars'ı
    // aşıyor) tek bir JSON string alanı olarak gelir - validasyondan önce
    // gerçek array'e çevrilir.
    protected function prepareForValidation(): void
    {
        foreach (['equipment', 'control_items'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $this->merge([$field => is_array($decoded) ? $decoded : []]);
            }
        }
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

            'equipment' => ['required', 'array', 'min:1'],
            'equipment.*.code' => ['nullable', 'string'],
            'equipment.*.properties' => ['nullable', 'array'],
            'equipment.*.result' => ['nullable', 'string'],
            'equipment.*.note' => ['nullable', 'string'],
            // Bu ekipmanın KENDİ kriterleri (örn. AKTAŞ'ta her tüp için 7
            // madde) - FireSuppressionUnifiedNormalizer::buildEquipmentEntry()
            // çıktısıyla aynı şekil (code/title/status), düz üst seviye
            // control_items listesinden BAĞIMSIZ, tek gerçek kaynak burasıdır.
            'equipment.*.control_items' => ['nullable', 'array'],
            'equipment.*.control_items.*.code' => ['nullable', 'string'],
            'equipment.*.control_items.*.title' => ['nullable', 'string'],
            'equipment.*.control_items.*.status' => ['nullable', 'string'],

            'control_items' => ['required', 'array'],
            'control_items.*.code' => ['required', 'string'],
            'control_items.*.criterion' => ['nullable', 'string'],
            'control_items.*.result' => ['nullable', 'string'],
            'control_items.*.result_normalized' => ['nullable', 'string'],
            'control_items.*.scope' => ['required', 'string', Rule::in(['system', 'equipment'])],
        ];
    }

    public function messages(): array
    {
        return [
            'control_date.required' => 'Kontrol tarihi zorunludur.',
            'control_date.date' => 'Kontrol tarihi geçerli olmalıdır.',
            'file.required' => 'Rapor PDF dosyası zorunludur.',
            'file.mimes' => 'Rapor dosyası PDF formatında olmalıdır.',
            'file.max' => 'Rapor dosyası en fazla 20 MB olabilir.',
            'equipment.required' => 'En az bir ekipman (tüp) gereklidir.',
        ];
    }
}
