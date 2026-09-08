<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\EmergencyEquipmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmergencyEquipmentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $equipmentTypeId = $this->route('emergencyEquipmentType');

        if ($equipmentTypeId instanceof EmergencyEquipmentType) {
            $equipmentTypeId = $equipmentTypeId->id;
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => [
                'nullable',
                'integer',
                'different:' . $equipmentTypeId,
                Rule::exists('emergency_equipment_types', 'id')->where('tenant_id', $tenantId),
            ],
            'capacity_kg' => ['required_with:parent_id', 'nullable', 'numeric', 'min:0'],
            'tip' => [
                'nullable',
                'string',
                'max:100',
                Rule::exists('emergency_equipment_type_tip_options', 'label')
                    ->where('equipment_type_id', $this->input('parent_id')),
            ],
            'inspection_frequency_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Ekipman türü adı zorunludur.',
            'name.max' => 'Ekipman türü adı en fazla 255 karakter olabilir.',
            'description.max' => 'Açıklama en fazla 1000 karakter olabilir.',
            'parent_id.integer' => 'Üst kategori ID değeri geçerli olmalıdır.',
            'parent_id.exists' => 'Seçilen üst kategori bu tenant içerisinde bulunamadı.',
            'parent_id.different' => 'Bir ekipman türü kendisini üst kategori olarak seçemez.',
            'capacity_kg.required_with' => 'Alt kategoriler için kapasite zorunludur.',
            'capacity_kg.numeric' => 'Kapasite geçerli bir sayı olmalıdır.',
            'capacity_kg.min' => 'Kapasite 0 veya daha büyük olmalıdır.',
            'tip.exists' => 'Seçilen tip, üst kategorinin tip seçenekleri arasında bulunamadı.',
            'inspection_frequency_days.integer' => 'Denetim sıklığı gün olarak tam sayı olmalıdır.',
            'inspection_frequency_days.min' => 'Denetim sıklığı en az 1 gün olmalıdır.',
            'is_active.boolean' => 'Aktif durumu geçerli bir boolean değer olmalıdır.',
        ];
    }
}
