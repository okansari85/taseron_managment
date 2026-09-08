<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Brand;
use App\Models\Location;
use App\Models\OperationalRegion;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kullanıcının header'daki aktif çalışma bağlamı (Organizasyon → Lokasyon →
 * Operasyonel Alan) için salt-okuma veri sağlar. Mevcut OrganizationService /
 * LocationService metodlarını değiştirmez, sadece onların verisini
 * (Organization::children(), organization_locations, OperationalRegion) okur.
 */
class WorkspaceContextService
{
    public function __construct(
        private TenantContext $tenantContext,
        private LocationExpertService $locationExpertService,
    ) {
    }

    /**
     * Header'ın ilk yüklemede ihtiyaç duyduğu özet: Operasyonel Alan bu
     * tenant'ta açık mı ve kullanıcının varsayılan (home) context'i ne.
     */
    public function bootstrap(User $user): array
    {
        $tenant = $this->tenantContext->get();
        $operationalAreaEnabled = (bool) $tenant->operational_area_enabled;

        $organizationScopeId = UserScope::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'organization')
            ->orderBy('id')
            ->value('scope_id');

        $locationScopeId = UserScope::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'location')
            ->orderBy('id')
            ->value('scope_id');

        $regionScopeId = $operationalAreaEnabled
            ? UserScope::query()
                ->where('user_id', $user->id)
                ->where('scope_type', 'operational_region')
                ->orderBy('id')
                ->value('scope_id')
            : null;

        // 'organization' scope'u olmayan kullanıcı için organizasyon/lokasyon
        // combo'larının bir anlamı yok — İSG uzmanı gibi doğrudan
        // location_experts üzerinden bir/birkaç operasyonel alana atanmış
        // olabilir. Bu durumda header, normal organizasyon akışı yerine
        // doğrudan bu alan(lar)la başlar.
        $locationExpertContext = $operationalAreaEnabled && $organizationScopeId === null
            ? $this->locationExpertContext($user)
            : ['mode' => 'none', 'regions' => []];

        return [
            'operational_area_enabled' => $operationalAreaEnabled,
            'location_view_mode' => $tenant->location_view_mode,
            'home' => [
                'organization_id' => $organizationScopeId,
                'location_id' => $locationScopeId,
                'operational_region_id' => $regionScopeId,
            ],
            'location_expert' => $locationExpertContext,
        ];
    }

    /**
     * location_experts tablosu üzerinden (LocationBusinessEntity ->
     * operational_region_id) kullanıcının doğrudan atandığı operasyonel
     * alanları döner. Tek alana atanmışsa 'locked' (seçim değiştirilemez,
     * direkt o alanla başlanır), birden fazlaysa 'choice' (sadece kendi
     * atandığı alanlar arasında seçim yapabilir), hiç atanmamışsa 'none'.
     */
    private function locationExpertContext(User $user): array
    {
        $assignments = $this->locationExpertService->forUser($user);

        $regionIds = $assignments
            ->pluck('locationBusinessEntity.operational_region_id')
            ->filter()
            ->unique()
            ->values();

        if ($regionIds->isEmpty()) {
            return ['mode' => 'none', 'regions' => []];
        }

        $regions = OperationalRegion::query()
            ->whereIn('id', $regionIds)
            ->with('location:id,name')
            ->get()
            ->map(fn (OperationalRegion $region) => [
                'id' => $region->id,
                'name' => $region->name,
                'location_id' => $region->location_id,
                'location_name' => $region->location?->name,
            ])
            ->values()
            ->all();

        return [
            'mode' => count($regions) === 1 ? 'locked' : 'choice',
            'regions' => $regions,
        ];
    }

    /**
     * Kullanıcının erişebildiği organizasyon düğümleri: kendi 'organization'
     * scope satırlarından başlayıp Organization::children() ile aşağı doğru
     * yürünerek toplanır. Hiç scope'u yoksa boş döner (kök tenant'a fallback
     * YOK — seçici bu durumda hiç render edilmemeli).
     */
    public function organizationOptions(User $user): array
    {
        $tenantId = $this->tenantContext->id();

        $rootIds = UserScope::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'organization')
            ->orderBy('id')
            ->pluck('scope_id');

        if ($rootIds->isEmpty()) {
            return ['home_organization_id' => null, 'items' => []];
        }

        $all = Organization::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['holding', 'group', 'company', 'brand'])
            ->get(['id', 'parent_id', 'name', 'type']);

        $childrenByParent = $all->groupBy('parent_id');
        $accessibleIds = collect();

        foreach ($rootIds as $rootId) {
            $accessibleIds = $accessibleIds->merge(
                $this->collectDescendantIds((int) $rootId, $childrenByParent)
            );
        }

        $accessibleIds = $accessibleIds->unique();

        // Organization tipi 'brand' olan düğümler, marka-şirket ilişkisi
        // başına türetilen kayıtlardır (aynı marka birden fazla şirkete bağlıysa
        // birden fazla düğüm oluşur — bkz. company_brands.brand_node_id). Bu
        // yüzden combo'da tekil, gerçek Brand kaydı gösterilir; Organization
        // düğümleri sadece erişim/lokasyon hesaplamasında iç detay olarak kalır.
        $items = $all->whereIn('id', $accessibleIds)
            ->where('type', '!=', 'brand')
            ->sortBy('name')
            ->values()
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'type' => $organization->type,
                'parent_id' => $organization->parent_id,
            ])
            ->all();

        $accessibleBrandNodeIds = $all->whereIn('id', $accessibleIds)
            ->where('type', 'brand')
            ->pluck('id');

        if ($accessibleBrandNodeIds->isNotEmpty()) {
            $brandIds = DB::table('company_brands')
                ->whereIn('brand_node_id', $accessibleBrandNodeIds)
                ->pluck('brand_id')
                ->unique();

            $brandItems = Brand::query()
                ->whereIn('id', $brandIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Brand $brand) => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'type' => 'brand',
                    'parent_id' => null,
                ])
                ->all();

            $items = [...$items, ...$brandItems];
        }

        return [
            'home_organization_id' => (int) $rootIds->first(),
            'items' => $items,
        ];
    }

    /**
     * Seçilen organizasyon düğümü (altındaki tüm şirketler) veya seçilen
     * marka altına bağlı lokasyonlar. $id kullanıcının erişilebilir
     * ağacında/markalarında değilse ValidationException fırlatır (controller
     * bunu 403'e çevirir).
     *
     * ÖNEMLİ: organization_locations pivot'u SADECE "bu lokasyon hangi
     * organizasyon düğümü altında oluşturuldu" bilgisini taşır — bir
     * lokasyonda gerçekte hangi şirket/markaların faaliyet gösterdiğini
     * YANSITMAZ (bir lokasyonda birden fazla şirket/marka olabilir, ör.
     * bir AVM'deki 3 farklı restoran şubesi). Gerçek "bu markaya/şirkete ait
     * lokasyonlar" ilişkisi location_business_entities (+ ..._brands pivot)
     * üzerinden kurulur — tüm ekranlarda bundan sonra bu kaynak kullanılır.
     */
    public function locationOptions(User $user, int $id, string $kind = 'organization'): array
    {
        $businessEntityRows = $this->resolveBusinessEntityRows($user, $id, $kind);
        $locationIds = $businessEntityRows->pluck('location_id')->unique();

        $items = Location::query()
            ->whereIn('id', $locationIds)
            ->with(['city:id,name', 'district:id,name,region_group'])
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'city' => $location->city?->name,
                'district' => $location->district?->name,
                // Bölge (İstanbul Avrupa/Asya Yakası gibi) manuel doldurulan bir alan;
                // henüz doldurulmamış ilçeler için null döner.
                'region' => $location->district?->region_group,
            ])
            ->all();

        return [
            'organization_id' => $id,
            'items' => $items,
        ];
    }

    /**
     * Seçilen organizasyon/marka'ya ait ham location_business_entities id'leri
     * — location_business_entities-bazlı ekranların (ör. şube listesi)
     * kendi filtrelerini kurabilmesi için. locationOptions() ile aynı
     * kaynağı kullanır, sadece lokasyon bazında değil satır bazında döner
     * (bir lokasyonda birden fazla şirket/marka olabildiği için "bu
     * lokasyon erişilebilir" ile "bu satır seçili markaya ait" farklı
     * şeylerdir).
     *
     * @return array<int, int>
     */
    public function businessEntityIds(User $user, int $id, string $kind = 'organization'): array
    {
        return $this->resolveBusinessEntityRows($user, $id, $kind)->pluck('id')->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{id:int,location_id:int}>
     */
    private function resolveBusinessEntityRows(User $user, int $id, string $kind): Collection
    {
        $tenantId = $this->tenantContext->id();
        $accessible = $this->organizationOptions($user);

        if ($kind === 'brand') {
            $accessibleBrandIds = collect($accessible['items'])
                ->where('type', 'brand')
                ->pluck('id');

            if (! $accessibleBrandIds->contains($id)) {
                throw ValidationException::withMessages([
                    'organization_id' => 'Bu markaya erişim yetkiniz yok.',
                ]);
            }

            return DB::table('location_business_entities')
                ->join('location_business_entity_brands', 'location_business_entity_brands.location_business_entity_id', '=', 'location_business_entities.id')
                ->where('location_business_entity_brands.brand_id', $id)
                ->select('location_business_entities.id', 'location_business_entities.location_id')
                ->distinct()
                ->get();
        }

        // Brand.id ve Organization.id ayrı id uzayları — 'organization'
        // kind'ında sadece gerçek organizasyon düğümleri (brand hariç)
        // kabul edilir, aksi halde bir marka id'si yanlışlıkla
        // organizasyon düğümü sanılıp erişim kontrolü atlanabilir.
        $accessibleIds = collect($accessible['items'])
            ->where('type', '!=', 'brand')
            ->pluck('id');

        if (! $accessibleIds->contains($id)) {
            throw ValidationException::withMessages([
                'organization_id' => 'Bu organizasyona erişim yetkiniz yok.',
            ]);
        }

        $allOrganizations = Organization::query()
            ->where('tenant_id', $tenantId)
            ->get(['id', 'parent_id', 'type']);
        $childrenByParent = $allOrganizations->groupBy('parent_id');
        $subtreeIds = $this->collectDescendantIds($id, $childrenByParent);

        $companyNodeIds = $allOrganizations->whereIn('id', $subtreeIds)->where('type', 'company')->pluck('id');

        $companyIds = $companyNodeIds->isEmpty()
            ? collect()
            : DB::table('organization_companies')
                ->whereIn('company_node_id', $companyNodeIds)
                ->pluck('company_id');

        if ($companyIds->isEmpty()) {
            return collect();
        }

        return DB::table('location_business_entities')
            ->join('business_entities', 'business_entities.id', '=', 'location_business_entities.business_entity_id')
            ->join('companies', 'companies.business_entity_id', '=', 'business_entities.id')
            ->whereIn('companies.id', $companyIds)
            ->select('location_business_entities.id', 'location_business_entities.location_id')
            ->distinct()
            ->get();
    }

    /**
     * Seçilen lokasyondaki operasyonel alanlar. Tenant'ta özellik kapalıysa
     * veya kullanıcının 'operational_region' scope'u kendisini başka
     * alanlara kısıtlıyorsa buna göre daraltılmış/boş liste döner.
     */
    public function operationalAreaOptions(User $user, int $locationId): array
    {
        $tenant = $this->tenantContext->get();

        if (! $tenant->operational_area_enabled) {
            return ['location_id' => $locationId, 'enabled' => false, 'items' => []];
        }

        $regionScopeIds = UserScope::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'operational_region')
            ->pluck('scope_id');

        $query = OperationalRegion::query()
            ->where('location_id', $locationId)
            ->where('is_active', true);

        if ($regionScopeIds->isNotEmpty()) {
            $query->whereIn('id', $regionScopeIds);
        }

        $items = $query->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (OperationalRegion $region) => [
                'id' => $region->id,
                'name' => $region->name,
            ])
            ->all();

        return ['location_id' => $locationId, 'enabled' => true, 'items' => $items];
    }

    /**
     * @param Collection<int, Organization> $childrenByParent groupBy('parent_id') sonucu
     * @return array<int, int>
     */
    private function collectDescendantIds(int $organizationId, Collection $childrenByParent): array
    {
        $ids = [$organizationId];

        foreach ($childrenByParent->get($organizationId, []) as $child) {
            $ids = array_merge($ids, $this->collectDescendantIds($child->id, $childrenByParent));
        }

        return $ids;
    }
}
