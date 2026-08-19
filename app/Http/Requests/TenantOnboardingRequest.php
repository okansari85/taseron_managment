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
             * Onboarding type
             */
            'onboarding_type' => [
                'required',
                Rule::in([
                    'holding',
                    'group',
                    'company',
                    'brand',
                ]),
            ],

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

            /*
             * Company
             *
             * Only required for company onboarding.
             */
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

            /*
             * Brand
             *
             * Only required for brand onboarding.
             */
            'brand' => [
                'nullable',
                'array',
                'required_if:onboarding_type,brand',
            ],

            'brand.name' => [
                'required_if:onboarding_type,brand',
                'nullable',
                'string',
                'max:255',
            ],

            /*
             * Location
             *
             * Company onboarding with an individual company type
             * requires the initial center location.
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
