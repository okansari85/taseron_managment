<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\FieldFinding;
use App\Models\LocationBusinessEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Throwable;

class FieldFindingService
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {
    }

    public function all(LocationBusinessEntity $locationBusinessEntity): Collection
    {
        $this->assertOwnership($locationBusinessEntity);

        return FieldFinding::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->with(['photos', 'reportedByUser'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(LocationBusinessEntity $locationBusinessEntity, array $data, ?User $actingUser): FieldFinding
    {
        $this->assertOwnership($locationBusinessEntity);

        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $photos = $data['photos'] ?? [];
        $storedPhotoPaths = [];

        try {
            return DB::transaction(function () use ($locationBusinessEntity, $data, $actingUser, $photos, &$storedPhotoPaths) {
                $finding = FieldFinding::query()->create([
                    'tenant_id' => $this->tenantContext->id(),
                    'location_business_entity_id' => $locationBusinessEntity->id,
                    'category' => $data['category'],
                    'location_note' => $data['location_note'] ?? null,
                    'description' => $data['description'] ?? null,
                    'severity' => $data['severity'],
                    'status' => 'open',
                    'reported_by_user_id' => $actingUser?->id,
                ]);

                $storedPhotoPaths = $this->addPhotos($finding, $photos);

                return $finding->load(['photos', 'reportedByUser']);
            });
        } catch (Throwable $exception) {
            foreach ($storedPhotoPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
    }

    public function update(FieldFinding $finding, array $data): FieldFinding
    {
        $this->assertBelongsToTenant($finding);

        $photos = $data['photos'] ?? [];
        $removePhotoIds = $data['remove_photo_ids'] ?? [];
        unset($data['photos'], $data['remove_photo_ids']);

        $removedPhotoPaths = [];
        $storedPhotoPaths = [];

        try {
            DB::transaction(function () use ($finding, $data, $photos, $removePhotoIds, &$removedPhotoPaths, &$storedPhotoPaths) {
                $finding->update($data);
                $removedPhotoPaths = $this->removePhotos($finding, $removePhotoIds);
                $storedPhotoPaths = $this->addPhotos($finding, $photos);
            });

            foreach ($removedPhotoPaths as $path) {
                Storage::disk('public')->delete($path);
            }
        } catch (Throwable $exception) {
            foreach ($storedPhotoPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }

        return $finding->load(['photos', 'reportedByUser']);
    }

    public function delete(FieldFinding $finding): void
    {
        $this->assertBelongsToTenant($finding);
        $finding->delete();
    }

    private function addPhotos(FieldFinding $finding, array $photos): array
    {
        $photos = array_values(array_filter($photos, fn ($photo) => $photo instanceof UploadedFile));

        if ($photos === []) {
            return [];
        }

        $nextOrder = ((int) DB::table('field_finding_photos')
            ->where('field_finding_id', $finding->id)
            ->max('order_no')) + 1;

        $storedPaths = [];
        $rows = [];
        $now = now();

        foreach ($photos as $photo) {
            $path = $photo->store('field-finding-photos', 'public');
            $storedPaths[] = $path;
            $rows[] = [
                'field_finding_id' => $finding->id,
                'photo_path' => $path,
                'order_no' => $nextOrder++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('field_finding_photos')->insert($rows);

        return $storedPaths;
    }

    private function removePhotos(FieldFinding $finding, array $photoIds): array
    {
        $photoIds = array_values(array_filter(array_map('intval', $photoIds)));

        if ($photoIds === []) {
            return [];
        }

        $paths = DB::table('field_finding_photos')
            ->where('field_finding_id', $finding->id)
            ->whereIn('id', $photoIds)
            ->pluck('photo_path')
            ->all();

        DB::table('field_finding_photos')
            ->where('field_finding_id', $finding->id)
            ->whereIn('id', $photoIds)
            ->delete();

        return $paths;
    }

    private function assertOwnership(LocationBusinessEntity $locationBusinessEntity): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        $tenantId = $locationBusinessEntity->location?->tenant_id
            ?? $locationBusinessEntity->location()->value('tenant_id');

        if ($tenantId !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu şubeye erişim yetkiniz yok.');
        }
    }

    private function assertBelongsToTenant(FieldFinding $finding): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($finding->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu bulguya erişim yetkiniz yok.');
        }
    }
}
