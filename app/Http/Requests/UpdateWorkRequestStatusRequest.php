<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkRequestStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected', 'completed'])],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Durum zorunludur.',
            'status.in' => 'Geçersiz iş talebi durumu.',
        ];
    }
}
