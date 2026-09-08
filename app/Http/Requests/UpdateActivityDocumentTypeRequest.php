<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActivityDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_required' => ['nullable', 'boolean'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Evrak adı zorunludur.',
            'name.max' => 'Evrak adı en fazla 255 karakter olabilir.',
            'is_required.boolean' => 'Zorunluluk geçerli bir boolean değer olmalıdır.',
            'validity_days.integer' => 'Geçerlilik süresi gün olarak tam sayı olmalıdır.',
            'validity_days.min' => 'Geçerlilik süresi en az 1 gün olmalıdır.',
            'description.max' => 'Açıklama en fazla 1000 karakter olabilir.',
        ];
    }
}
