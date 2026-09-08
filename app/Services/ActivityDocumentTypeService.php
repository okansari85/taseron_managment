<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Activity;
use App\Models\ActivityDocumentType;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ActivityDocumentTypeService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function listForActivity(Activity $activity, ?string $target = null): Collection
    {
        return $activity->documentTypes()
            ->when($target, fn ($query) => $query->where('target', $target))
            ->with('documentType')
            ->latest('id')
            ->get();
    }

    public function attach(Activity $activity, array $data): ActivityDocumentType
    {
        return DB::transaction(function () use ($activity, $data) {
            $documentType = DocumentType::query()->firstOrCreate(
                [
                    'tenant_id' => $this->tenantContext->id(),
                    'name' => $data['name'],
                ],
                [
                    'type' => $data['type'] ?? 'document_upload',
                    'is_active' => true,
                ]
            );

            return $activity->documentTypes()->create([
                'document_type_id' => $documentType->id,
                'target' => $data['target'],
                'is_required' => $data['is_required'] ?? true,
                'validity_days' => $data['validity_days'] ?? null,
                'description' => $data['description'] ?? null,
            ])->load('documentType');
        });
    }

    public function update(ActivityDocumentType $item, array $data): ActivityDocumentType
    {
        return DB::transaction(function () use ($item, $data) {
            if (array_key_exists('name', $data) && $item->documentType->name !== $data['name']) {
                $item->documentType->update(['name' => $data['name']]);
            }

            $item->update([
                'is_required' => $data['is_required'] ?? $item->is_required,
                'validity_days' => array_key_exists('validity_days', $data) ? $data['validity_days'] : $item->validity_days,
                'description' => array_key_exists('description', $data) ? $data['description'] : $item->description,
            ]);

            return $item->refresh()->load('documentType');
        });
    }

    public function delete(ActivityDocumentType $item): void
    {
        $item->delete();
    }
}
