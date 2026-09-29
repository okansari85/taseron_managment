<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * pktakip: müşteriyi altındaki her şeyle birlikte kalıcı siler — organizasyon ağacı, lokasyonlar, işyerleri,
 * periyodik kontrol ekipmanları, kontrol kayıtları ve rapor / fotoğraf dosyaları. Mevcut müşteri silme
 * (yalnızca müşteri kaydı) değiştirilmez. Taşeron yönetimine ait kayıt (rapor, bulgu, iş talebi, taşeron...)
 * varsa hiçbir şey silinmez.
 */
class CustomerPurgeService
{
    // İşyerine bağlı taşeron yönetimi kayıtları.
    private const WORKPLACE_BLOCKERS = [
        'fire_suppression_reports' => 'yangın söndürme raporu',
        'fire_suppression_inventory_items' => 'yangın envanter kaydı',
        'emergency_equipment_annual_control_reports' => 'acil durum ekipmanı kontrol raporu',
        'location_emergency_equipment' => 'acil durum ekipmanı',
        'field_findings' => 'saha bulgusu',
    ];

    public function __construct(private TenantContext $tenantContext)
    {
    }

    // Silme onayı için: altında ne var, silinebilir mi.
    public function summary(Customer $customer): array
    {
        $scope = $this->scope($customer);

        return [
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            'organizations' => $scope['organizations']->count(),
            'locations' => $scope['locations']->count(),
            'workplaces' => $scope['workplaces']->count(),
            'equipment' => $scope['equipment']->count(),
            'inspections' => DB::table('pk_inspections')->whereIn('pk_equipment_id', $scope['equipment']->pluck('id'))->count(),
            'blockers' => $scope['blockers'],
        ];
    }

    public function purge(Customer $customer): void
    {
        $scope = $this->scope($customer);
        if ($scope['blockers']) {
            throw ValidationException::withMessages(['customer' => 'Müşteri silinemedi: ' . implode(', ', $scope['blockers']) . '.']);
        }

        $equipmentIds = $scope['equipment']->pluck('id');
        $workplacePhotos = DB::table('location_business_entity_photos')->whereIn('location_business_entity_id', $scope['workplaces'])->pluck('photo_path');

        DB::transaction(function () use ($customer, $scope, $equipmentIds) {
            // Silinen ekipmanlardan açılmış, onay bekleyen katalog talepleri de gider.
            DB::table('periodic_equipment_spec_requests')->whereIn('pk_equipment_id', $equipmentIds)->where('status', 'pending')->delete();
            // Kontrol kayıtları ve teknik özellikler ekipmanla birlikte (cascade).
            DB::table('pk_equipment')->whereIn('id', $equipmentIds)->delete();
            // İşyerleri, işyeri fotoğraf kayıtları, uzman atamaları, organizasyon bağları lokasyonla birlikte (cascade).
            DB::table('locations')->whereIn('id', $scope['locations'])->delete();
            // Organizasyonlar: üst-alt bağı (parent_id) silmeyi engellemesin diye önce çözülür.
            DB::table('organizations')->whereIn('id', $scope['organizations'])->update(['parent_id' => null]);
            DB::table('organizations')->whereIn('id', $scope['organizations'])->delete();
            $customer->delete();
        });

        // Dosyalar yalnızca veritabanı silindikten sonra.
        $scope['equipment']->pluck('photo_path')->filter()->each(fn (string $path) => Storage::disk('public')->delete($path));
        $equipmentIds->each(fn ($id) => Storage::disk('local')->deleteDirectory("pk-inspections/{$id}"));
        $workplacePhotos->filter()->each(fn (string $path) => Storage::disk('public')->delete($path));
    }

    /**
     * Müşterinin organizasyon ağacı (kök bağlardan aşağı), yalnızca bu ağaca bağlı lokasyonlar, işyerleri, ekipmanlar
     * ve silmeyi engelleyen durumlar.
     *
     * @return array{organizations: Collection, locations: Collection, workplaces: Collection, equipment: Collection, blockers: array<int, string>}
     */
    private function scope(Customer $customer): array
    {
        $tenantId = $this->tenantContext->id();
        if ($customer->tenant_id !== $tenantId) {
            abort(404);
        }

        $organizations = DB::table('customer_organization')->where('customer_id', $customer->id)->pluck('organization_id')->map(fn ($id) => (int) $id);
        do {
            $children = DB::table('organizations')->where('tenant_id', $tenantId)->whereIn('parent_id', $organizations)->whereNotIn('id', $organizations)->pluck('id')->map(fn ($id) => (int) $id);
            $organizations = $organizations->merge($children)->unique()->values();
        } while ($children->isNotEmpty());

        $blockers = [];
        $sharedOrganizations = DB::table('customer_organization')->whereIn('organization_id', $organizations)->where('customer_id', '!=', $customer->id)->count();
        if ($sharedOrganizations) {
            $blockers[] = "{$sharedOrganizations} organizasyon başka bir müşteriye de bağlı";
        }

        // Başka müşterinin organizasyonuna da bağlı lokasyon silinmez (yalnızca bu müşterinin bağı kalkar).
        $linked = DB::table('organization_locations')->whereIn('organization_id', $organizations)->pluck('location_id')->unique();
        $shared = DB::table('organization_locations')->whereIn('location_id', $linked)->whereNotIn('organization_id', $organizations)->pluck('location_id')->unique();
        $locations = DB::table('locations')->where('tenant_id', $tenantId)->whereIn('id', $linked->diff($shared))->pluck('id');

        $workplaces = DB::table('location_business_entities')->whereIn('location_id', $locations)->pluck('id');
        $equipment = DB::table('pk_equipment')->whereIn('location_id', $locations)->get(['id', 'photo_path']);

        foreach (self::WORKPLACE_BLOCKERS as $table => $label) {
            if ($count = DB::table($table)->whereIn('location_business_entity_id', $workplaces)->count()) {
                $blockers[] = "{$count} {$label}";
            }
        }
        if ($count = DB::table('work_requests')->where(fn ($query) => $query->whereIn('location_id', $locations)->orWhereIn('organization_id', $organizations))->count()) {
            $blockers[] = "{$count} iş talebi";
        }
        if ($count = DB::table('operational_regions')->whereIn('location_id', $locations)->count()) {
            $blockers[] = "{$count} operasyon bölgesi";
        }
        if ($count = DB::table('organization_contractors')->whereIn('organization_id', $organizations)->count()) {
            $blockers[] = "{$count} taşeron bağlantısı";
        }
        if ($count = DB::table('organization_companies')->whereIn('organization_id', $organizations)->count()) {
            $blockers[] = "{$count} organizasyon-firma bağlantısı";
        }
        if ($blockers) {
            // Taşeron yönetimine ait kayıtlar buradan silinmez.
            $blockers[] = 'bunlar taşeron yönetimine ait kayıtlar, buradan silinmez';
        }

        return ['organizations' => $organizations, 'locations' => $locations, 'workplaces' => $workplaces, 'equipment' => $equipment, 'blockers' => $blockers];
    }
}
