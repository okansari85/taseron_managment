<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target' => ['required', Rule::in(['company', 'personnel'])],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['document_upload', 'training_video'])],
            'is_required' => ['nullable', 'boolean'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'target.required' => 'Hedef (firma/personel) zorunludur.',
            'target.in' => 'Geçersiz hedef.',
            'name.required' => 'Evrak adı zorunludur.',
            'name.max' => 'Evrak adı en fazla 255 karakter olabilir.',
            'type.in' => 'Geçersiz evrak tipi.',
            'is_required.boolean' => 'Zorunluluk geçerli bir boolean değer olmalıdır.',
            'validity_days.integer' => 'Geçerlilik süresi gün olarak tam sayı olmalıdır.',
            'validity_days.min' => 'Geçerlilik süresi en az 1 gün olmalıdır.',
            'description.max' => 'Açıklama en fazla 1000 karakter olabilir.',
        ];
    }
}
