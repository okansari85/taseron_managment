<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_ids' => [
                'sometimes',
                'array',
                'min:1',
            ],

            'company_ids.*' => [
                'integer',
                'distinct',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }
}
