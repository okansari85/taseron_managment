<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contractor_id' => ['required', 'integer'],
            'organization_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requested_date' => ['nullable', 'date'],
            'requested_by_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'contractor_id.required' => 'Taşeron seçimi zorunludur.',
            'title.required' => 'İş talebi başlığı zorunludur.',
            'title.max' => 'İş talebi başlığı en fazla 255 karakter olabilir.',
            'requested_date.date' => 'Planlanan tarih geçerli bir tarih olmalıdır.',
        ];
    }
}
