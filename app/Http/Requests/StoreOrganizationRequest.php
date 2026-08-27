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

            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('organizations', 'slug')
                    ->where('tenant_id', $tenantId),
            ],

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],

            'code' => [
                'nullable',
                'string',
                'max:100',
            ],

            'display_order' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],

            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('organizations', 'id')
                    ->where('tenant_id', $tenantId),
            ],

            'color' => [
                'required',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
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

            'slug.required' => 'Slug zorunludur.',
            'slug.string' => 'Slug geçerli bir metin olmalıdır.',
            'slug.max' => 'Slug en fazla 255 karakter olabilir.',
            'slug.unique' => 'Bu slug tenant içerisinde zaten kullanılmaktadır.',

            'description.string' => 'Açıklama geçerli bir metin olmalıdır.',
            'description.max' => 'Açıklama en fazla 500 karakter olabilir.',

            'code.string' => 'Grup kodu geçerli bir metin olmalıdır.',
            'code.max' => 'Grup kodu en fazla 100 karakter olabilir.',

            'display_order.integer' => 'Sıra değeri tam sayı olmalıdır.',
            'display_order.min' => 'Sıra değeri 0 veya daha büyük olmalıdır.',

            'is_active.required' => 'Aktif durumu zorunludur.',
            'is_active.boolean' => 'Aktif durumu geçerli bir boolean değer olmalıdır.',

            'parent_id.integer' => 'Üst organizasyon ID değeri geçerli olmalıdır.',
            'parent_id.exists' => 'Seçilen üst organizasyon bu tenant içerisinde bulunamadı.',

            'color.required' => 'Görünüm rengi zorunludur.',
            'color.regex' => 'Görünüm rengi geçerli bir HEX renk değeri olmalıdır.',
        ];
    }
}
