<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOperationalRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => [
                'sometimes',
                Rule::in(['facility', 'warehouse', 'business', 'depot', 'office', 'store']),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
