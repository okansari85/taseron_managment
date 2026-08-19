<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncBrandLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_ids' => [
                'required',
                'array',
            ],

            'location_ids.*' => [
                'integer',
                'distinct',
            ],
        ];
    }
}
