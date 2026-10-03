<?php

namespace App\Http\Controllers;

use App\Models\BusinessEntity;
use App\Services\CompanyService;
use App\Services\PkAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Firma silme (pktakip Ayarlar → Firmalar): yalnızca hiç verisi olmayan firma silinir. Veri: firmanın işyerlerine (lokasyon
 * bağlantısı) bağlı herhangi bir kayıt (ekipman, tesisat, tüp formu, uzman ataması, Taşeron kayıtları…) ya da firmaya bağlı
 * taşeron / organizasyon / marka kaydı. Bağlı tablolar veritabanından bulunur (ileride eklenen tablo da engel sayılır).
 * Verisi olmayan işyerleri (lokasyon bağlantıları) firmayla birlikte kaldırılır; firma Taşeron'un mevcut silme işlemiyle silinir.
 * Yetki: OSGB / kurumsal hesapta yönetici, bireysel uzman hesabında uzman (firma ünvanındaki gibi).
 */
class PkCompanyDeletionController extends Controller
{
    // Bilinen tabloların ekranda adı; bilinmeyen tablo adıyla yazılır.
    private const LABELS = [
        'pk_equipment' => 'ekipman',
        'pk_installations' => 'tesisat',
        'pk_bulk_reports' => 'tüp kontrol formu',
        'location_experts' => 'uzman ataması',
        'location_emergency_equipment' => 'acil durum ekipmanı (Taşeron)',
        'location_business_entity_photos' => 'fotoğraf (Taşeron)',
        'field_findings' => 'saha bulgusu (Taşeron)',
        'fire_suppression_inventory_items' => 'yangın söndürme envanteri (Taşeron)',
        'fire_suppression_reports' => 'yangın söndürme raporu (Taşeron)',
        'emergency_equipment_annual_control_reports' => 'acil durum yıllık kontrol raporu (Taşeron)',
        'location_business_entity_brands' => 'marka bağlantısı (Taşeron)',
        'contractors' => 'taşeron kaydı (Taşeron)',
        'organization_companies' => 'organizasyon üyeliği (Taşeron)',
        'company_brands' => 'marka kaydı (Taşeron)',
    ];
    // Firmayla birlikte giden kendi kayıtları (engel değil): firma satırı, lokasyon bağlantıları, ünvan bağı.
    private const OWN_TABLES = ['companies', 'location_business_entities', 'pk_company_title_links'];

    public function __construct(private PkAccountService $accounts, private CompanyService $companies)
    {
    }

    // Silmeden önce: silinebilir mi, neden silinemez, kaç lokasyon bağlantısı birlikte kalkar.
    public function summary(Request $request, BusinessEntity $businessEntity): JsonResponse
    {
        $this->authorize($request, $businessEntity);

        return response()->json($this->usage($businessEntity));
    }

    public function destroy(Request $request, BusinessEntity $businessEntity): JsonResponse
    {
        $this->authorize($request, $businessEntity);

        DB::transaction(function () use ($businessEntity) {
            $usage = $this->usage($businessEntity, true);
            if (!$usage['deletable']) {
                throw ValidationException::withMessages(['company' => 'Bu firma silinemez: ' . collect($usage['blockers'])->map(fn ($row) => "{$row['count']} {$row['label']}")->join(', ') . ' var.']);
            }
            DB::table('location_business_entities')->where('business_entity_id', $businessEntity->id)->delete();
            if ($company = $businessEntity->company) {
                // Firma satırı, iş ortağı kaydı (business entity) ve Taşeron'un ilişki düğümleri: mevcut silme işlemi.
                $this->companies->delete($company);
            } else {
                $businessEntity->delete();
            }
        });

        return response()->json(['message' => 'Firma silindi.']);
    }

    private function authorize(Request $request, BusinessEntity $businessEntity): void
    {
        $tenant = $this->accounts->tenantOf($request->user());
        abort_unless($tenant && (int) $businessEntity->tenant_id === $tenant->id, 404, 'Firma bu hesapta değil.');
        abort_if(in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true) && $this->accounts->roleOf($tenant, $request->user()) !== 'yonetici', 403, 'Firmayı yönetici siler.');
        if ($businessEntity->type !== 'company') {
            throw ValidationException::withMessages(['company' => 'Yalnızca firmalar silinebilir.']);
        }
    }

    /** @return array{deletable: bool, locations: int, blockers: array<int, array{label: string, count: int}>} */
    private function usage(BusinessEntity $businessEntity, bool $lock = false): array
    {
        $links = DB::table('location_business_entities')->where('business_entity_id', $businessEntity->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->pluck('id');
        $companyId = DB::table('companies')->where('business_entity_id', $businessEntity->id)->value('id');

        $counts = [];
        foreach ($this->referencingTables() as [$table, $column]) {
            if (in_array($table, self::OWN_TABLES, true)) {
                continue;
            }
            $count = $column === 'location_business_entity_id'
                ? ($links->isEmpty() ? 0 : DB::table($table)->whereIn($column, $links)->count())
                : DB::table($table)->where($column, $businessEntity->id)->count();
            if ($count) {
                $counts[$table] = ($counts[$table] ?? 0) + $count;
            }
        }
        foreach (['organization_companies', 'company_brands'] as $table) {
            $count = $companyId ? DB::table($table)->where('company_id', $companyId)->count() : 0;
            if ($count) {
                $counts[$table] = $count;
            }
        }

        $blockers = collect($counts)->map(fn (int $count, string $table) => ['label' => self::LABELS[$table] ?? "kayıt ({$table})", 'count' => $count])->values()->all();

        return ['deletable' => $blockers === [], 'locations' => $links->count(), 'blockers' => $blockers];
    }

    // Firmaya (business_entity_id) ya da işyerine (location_business_entity_id) sütunuyla bağlanan bütün tablolar.
    private function referencingTables(): array
    {
        return collect(DB::select(
            'select TABLE_NAME as t, COLUMN_NAME as c from information_schema.COLUMNS where TABLE_SCHEMA = ? and COLUMN_NAME in (?, ?)',
            [DB::getDatabaseName(), 'business_entity_id', 'location_business_entity_id']
        ))->map(fn ($row) => [$row->t, $row->c])->all();
    }
}
