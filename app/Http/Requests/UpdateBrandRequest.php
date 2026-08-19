<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => [
                'sometimes',
                'integer',
            ],

            'name' => [
                'sometimes',
                'string',
                'max:255',
            ],
        ];
    }
}
