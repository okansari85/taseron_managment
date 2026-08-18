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
            /*
             * Tenant
             */
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

            /*
             * Root Organization
             */
            'organization' => [
                'required',
                'array',
            ],

            'organization.name' => [
                'required',
                'string',
                'max:255',
            ],

            'organization.type' => [
                'required',
                'string',
            ],

            /*
             * Company
             */
            'company' => [
                'required',
                'array',
            ],

            'company.name' => [
                'required',
                'string',
                'max:255',
            ],

            'company.company_type' => [
                'required',
                Rule::in([
                    'individual',
                    'corporate',
                ]),
            ],

            /*
             * Location
             *
             * Şahıs firmasında zorunlu.
             * Tüzel kişilikte opsiyonel.
             */
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
        ];
    }
}
