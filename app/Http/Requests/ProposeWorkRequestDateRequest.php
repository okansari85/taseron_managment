<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProposeWorkRequestDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proposed_date' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'proposed_date.required' => 'Önerilen tarih zorunludur.',
            'proposed_date.date' => 'Önerilen tarih geçerli bir tarih olmalıdır.',
        ];
    }
}
