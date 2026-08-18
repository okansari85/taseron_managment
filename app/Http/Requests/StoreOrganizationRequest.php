<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'type' => [
                'required',
                Rule::in([
                    'holding',
                    'group',
                    'company',
                    'brand',
                    'location',
                ]),
            ],

            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('organizations', 'id')
                    ->where('tenant_id', $tenantId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Organizasyon adı zorunludur.',
            'name.string' => 'Organizasyon adı geçerli bir metin olmalıdır.',
            'name.max' => 'Organizasyon adı en fazla 255 karakter olabilir.',

            'type.required' => 'Organizasyon tipi zorunludur.',
            'type.in' => 'Geçersiz organizasyon tipi.',

            'parent_id.integer' => 'Üst organizasyon ID değeri geçerli olmalıdır.',
            'parent_id.exists' => 'Seçilen üst organizasyon bu tenant içerisinde bulunamadı.',
        ];
    }
}