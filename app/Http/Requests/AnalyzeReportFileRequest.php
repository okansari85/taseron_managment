<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeReportFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->boolean('gemini_fixture_v12')) {
            return [
                'fixture_id' => ['required', 'uuid'],
            ];
        }

        if ($this->boolean('gemini_fixture_list')) {
            return [];
        }

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
            'fixture_id.required' => 'Fixture ID gereklidir.',
            'fixture_id.uuid' => 'Geçersiz fixture ID.',
        ];
    }
}
