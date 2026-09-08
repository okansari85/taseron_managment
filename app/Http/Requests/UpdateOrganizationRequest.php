<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $organizationId = $this->route('organization');

        if ($organizationId instanceof \App\Models\Organization) {
            $organizationId = $organizationId->id;
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => [
                'required',
                Rule::in(['holding', 'group', 'company', 'brand']),
            ],
            'slug' => [
                'required_if:type,group',
                'nullable',
                'string',
                'max:255',
                Rule::unique('organizations', 'slug')
                    ->where('tenant_id', $tenantId)
                    ->ignore($organizationId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'code' => ['nullable', 'string', 'max:100'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'parent_id' => [
                'nullable',
                'integer',
                'different:' . $organizationId,
                Rule::exists('organizations', 'id')->where('tenant_id', $tenantId),
            ],
            'color' => [
                'required_if:type,group',
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],
            'default_brand_id' => [
                'nullable',
                'integer',
                Rule::exists('brands', 'id')->where('tenant_id', $tenantId),
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
            'slug.required_if' => 'Grup için slug zorunludur.',
            'slug.string' => 'Slug geçerli bir metin olmalıdır.',
            'slug.max' => 'Slug en fazla 255 karakter olabilir.',
            'slug.unique' => 'Bu slug tenant içerisinde zaten kullanılmaktadır.',
            'description.string' => 'Açıklama geçerli bir metin olmalıdır.',
            'description.max' => 'Açıklama en fazla 500 karakter olabilir.',
            'code.string' => 'Kod geçerli bir metin olmalıdır.',
            'code.max' => 'Kod en fazla 100 karakter olabilir.',
            'display_order.integer' => 'Sıra değeri tam sayı olmalıdır.',
            'display_order.min' => 'Sıra değeri 0 veya daha büyük olmalıdır.',
            'is_active.boolean' => 'Aktif durumu geçerli bir boolean değer olmalıdır.',
            'parent_id.integer' => 'Üst organizasyon ID değeri geçerli olmalıdır.',
            'parent_id.exists' => 'Seçilen üst organizasyon bu tenant içerisinde bulunamadı.',
            'parent_id.different' => 'Bir organizasyon kendisini üst organizasyon olarak seçemez.',
            'color.required_if' => 'Grup için görünüm rengi zorunludur.',
            'color.regex' => 'Görünüm rengi geçerli bir HEX renk değeri olmalıdır.',
            'default_brand_id.integer' => 'Varsayılan marka ID değeri geçerli olmalıdır.',
            'default_brand_id.exists' => 'Seçilen marka bu tenant içerisinde bulunamadı.',
        ];
    }
}
