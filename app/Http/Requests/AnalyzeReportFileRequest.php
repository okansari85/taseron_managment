<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Fire Suppression Reports ve YSC Yıllık Kontrol Raporları'nın "analyze"
// (AI ön-analiz taslağı) uç noktalarında paylaşılan tek istek — sadece PDF
// alır, hiçbir şey kaydetmez.
class AnalyzeReportFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Analiz için bir PDF dosyası gereklidir.',
            'file.mimes' => 'Dosya PDF formatında olmalıdır.',
            'file.max' => 'Dosya en fazla 20 MB olabilir.',
        ];
    }
}
