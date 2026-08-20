<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'onboarding_type' => [
                'required',
                Rule::in([
                    'holding',
                    'group',
                    'company',
                ]),
            ],

            'tenant' => [
                'required',
                'array',
            ],

            'tenant.name' => [
                'required',
                'string',
                'max:255',
            ],

            'tenant.slug' => [
                'required',
                'string',
                'max:255',
            ],

            'organization' => [
                'required',
                'array',
            ],

            'organization.name' => [
                'required',
                'string',
                'max:255',
            ],

            'company' => [
                'nullable',
                'array',
                'required_if:onboarding_type,company',
            ],

            'company.name' => [
                'required_if:onboarding_type,company',
                'nullable',
                'string',
                'max:255',
            ],

            'company.company_type' => [
                'required_if:onboarding_type,company',
                'nullable',
                Rule::in([
                    'individual',
                    'corporate',
                ]),
            ],

            'location' => [
                'nullable',
                'array',
            ],

            'location.name' => [
                'required_if:company.company_type,individual',
                'nullable',
                'string',
                'max:255',
            ],

            'logo' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,svg',
                'max:2048',
            ],
        ];
    }
}
