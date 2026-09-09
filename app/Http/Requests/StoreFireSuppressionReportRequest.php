<?php

namespace App\Http\Requests;

use App\Models\FireSuppressionInventoryItem;
use App\Models\FireSuppressionReportControlItem;
use App\Models\FireSuppressionReportFile;
use App\Models\FireSuppressionReportFinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFireSuppressionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_date' => ['required', 'date'],
            'report_no' => ['nullable', 'string', 'max:100'],
            'inspection_company_name' => ['nullable', 'string', 'max:255'],
            'next_control_date' => ['nullable', 'date'],
            'covered_categories' => ['nullable', 'array'],
            'covered_categories.*' => [Rule::in(FireSuppressionInventoryItem::CATEGORIES)],
            'overall_result' => ['nullable', 'string', Rule::in(FireSuppressionInventoryItem::COMPLIANCE_STATUSES)],
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'findings' => ['nullable', 'array'],
            'findings.*.category' => ['nullable', 'string', Rule::in(FireSuppressionInventoryItem::CATEGORIES)],
            'findings.*.control_item' => ['nullable', 'string', 'max:100'],
            'findings.*.description' => ['required', 'string', 'max:2000'],
            'findings.*.scope' => ['required', 'string', Rule::in(FireSuppressionReportFinding::SCOPES)],
            'findings.*.area_note' => ['nullable', 'string', 'max:255'],
            'findings.*.affected_item_ids' => ['nullable', 'array'],
            'findings.*.affected_item_ids.*' => ['integer', 'exists:fire_suppression_inventory_items,id'],

            'covered_inventory_item_ids' => ['nullable', 'array'],
            'covered_inventory_item_ids.*' => ['integer', 'exists:fire_suppression_inventory_items,id'],

            'control_items' => ['nullable', 'array'],
            'control_items.*.template_id' => ['nullable', 'integer', 'exists:fire_suppression_control_item_templates,id'],
            'control_items.*.category' => ['nullable', 'string', Rule::in(FireSuppressionInventoryItem::CATEGORIES)],
            'control_items.*.code' => ['nullable', 'string', 'max:20'],
            'control_items.*.section' => ['nullable', 'string', 'max:150'],
            'control_items.*.title' => ['required_with:control_items', 'string', 'max:255'],
            'control_items.*.status' => ['required_with:control_items', 'string', Rule::in(FireSuppressionReportControlItem::STATUSES)],
            'control_items.*.description' => ['nullable', 'string', 'max:1000'],

            'additional_files' => ['nullable', 'array'],
            'additional_files.*.file' => ['required_with:additional_files', 'file', 'max:20480'],
            'additional_files.*.type' => ['required_with:additional_files', 'string', Rule::in(FireSuppressionReportFile::TYPES)],
            'additional_files.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'report_date.required' => 'Rapor tarihi zorunludur.',
            'report_date.date' => 'Rapor tarihi geçerli olmalıdır.',
            'next_control_date.date' => 'Sonraki kontrol tarihi geçerli olmalıdır.',
            'file.required' => 'Rapor PDF dosyası zorunludur.',
            'file.mimes' => 'Rapor dosyası PDF formatında olmalıdır.',
            'file.max' => 'Rapor dosyası en fazla 20 MB olabilir.',
            'findings.*.description.required' => 'Uygunsuzluk açıklaması zorunludur.',
            'findings.*.scope.required' => 'Uygunsuzluk kapsamı seçilmelidir.',
            'findings.*.scope.in' => 'Geçersiz kapsam.',
            'findings.*.affected_item_ids.*.exists' => 'Seçilen ekipmanlardan biri bulunamadı.',
            'covered_inventory_item_ids.*.exists' => 'Seçilen ekipmanlardan biri bulunamadı.',
        ];
    }
}
