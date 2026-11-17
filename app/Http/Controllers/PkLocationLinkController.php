<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Organization;
use App\Services\CustomerOrganizationService;
use App\Services\OrganizationLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * pktakip: lokasyon hesabın (tenant) kaydıdır; ana firmaya (müşteri) bağı isteğe bağlıdır (KSY-SIS'teki gibi).
 *
 * - candidates: bu ana firmanın ağacına bağlı olmayan, hesaptaki lokasyonlar (ada / ile göre arama). Şemadaki "Lokasyon ekle"
 *   önce bunları gösterir; bulunamazsa mevcut uçla (customers/{c}/organizations/{o}/locations) yeni lokasyon açılır.
 * - store: kayıtlı lokasyonu ana firmanın bir düğümüne (kök ya da dal) bağlar.
 * - destroy: lokasyonu yalnız bu ana firmadan ayırır. Lokasyon başka bir yere de bağlı değilse 422 döner (önyüz bu durumda
 *   bugünkü silme akışına geçer); böylece ortak lokasyonu silmek öbür ana firmanın işyeri ve ekipmanlarını silmez.
 * Mevcut uç ve servisler değişmez; tenant kontrolü tree() ve OrganizationLocationService içinde yapılır.
 */
class PkLocationLinkController extends Controller
{
    public function __construct(
        private CustomerOrganizationService $customerOrganizationService,
        private OrganizationLocationService $organizationLocationService
    ) {
    }

    public function candidates(Request $request, Customer $customer): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $linked = $this->organizationIds($customer);

        $items = Location::query()
            ->whereDoesntHave('organizations', fn ($query) => $query->whereIn('organizations.id', $linked->all()))
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$q}%")
                ->orWhereHas('city', fn ($city) => $city->where('name', 'like', "%{$q}%"))
                ->orWhereHas('district', fn ($district) => $district->where('name', 'like', "%{$q}%"))))
            ->with(['city:id,name', 'district:id,name'])
            ->withCount('organizations')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'city' => $location->city?->name,
                'district' => $location->district?->name,
                'address' => $location->address,
                // 0: hiçbir ana firmaya bağlı değil (serbest lokasyon)
                'links' => $location->organizations_count,
            ]);

        return response()->json($items->values());
    }

    public function store(Request $request, Customer $customer, Organization $organization): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);
        $linked = $this->organizationIds($customer);

        if (! $linked->contains((int) $organization->id)) {
            throw ValidationException::withMessages(['organization' => 'Seçilen organizasyon bu ana firmaya bağlı değil.']);
        }

        // TenantScope: başka hesabın lokasyonu bulunamaz (404).
        $location = Location::query()->findOrFail($data['location_id']);

        if ($location->organizations()->whereIn('organizations.id', $linked->all())->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Bu lokasyon bu ana firmaya zaten bağlı.']);
        }

        $this->organizationLocationService->attach($organization, $location);

        return response()->json(['id' => $location->id, 'name' => $location->name], 201);
    }

    public function destroy(Customer $customer, Location $location): JsonResponse
    {
        $linked = $this->organizationIds($customer);
        $here = $location->organizations()->whereIn('organizations.id', $linked->all())->get();

        if ($here->isEmpty()) {
            throw ValidationException::withMessages(['location' => 'Bu lokasyon bu ana firmaya bağlı değil.']);
        }
        // Yalnız bu ana firmaya bağlıysa önyüz bugünkü silme akışına geçer (işyerleri + lokasyon).
        if ($location->organizations()->whereNotIn('organizations.id', $linked->all())->doesntExist()) {
            return response()->json(['code' => 'tek_bag', 'message' => 'Lokasyon yalnız bu ana firmaya bağlı.'], 422);
        }
        // KSY-SIS: ortak lokasyonda bu ana firmanın işyeri varken bağ kaldırılmaz.
        if (DB::table('location_business_entities')->where('location_id', $location->id)->where('customer_id', $customer->id)->exists()) {
            throw ValidationException::withMessages(['location' => 'Bu ana firmanın bu lokasyonda işyeri var; önce işyerlerini kaldırın.']);
        }

        DB::transaction(fn () => $here->each(fn (Organization $organization) => $this->organizationLocationService->detach($organization, $location)));

        return response()->json(['detached' => true]);
    }

    // Şemada sürükle-bırak: lokasyonu bu ana firmanın başka bir düğümüne taşır; öbür ana firmalardaki bağlar kalır.
    public function move(Request $request, Customer $customer, Location $location): JsonResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'integer']]);
        $linked = $this->organizationIds($customer);

        if (! $linked->contains((int) $data['organization_id'])) {
            throw ValidationException::withMessages(['organization_id' => 'Seçilen organizasyon bu ana firmaya bağlı değil.']);
        }
        $here = $location->organizations()->whereIn('organizations.id', $linked->all())->get();
        if ($here->isEmpty()) {
            throw ValidationException::withMessages(['location' => 'Bu lokasyon bu ana firmaya bağlı değil.']);
        }
        $target = Organization::query()->findOrFail($data['organization_id']);

        DB::transaction(function () use ($here, $target, $location) {
            $here->each(fn (Organization $organization) => $this->organizationLocationService->detach($organization, $location));
            $this->organizationLocationService->attach($target, $location);
        });

        return response()->json(['id' => $location->id, 'organization_id' => $target->id]);
    }

    // Şemada sürükle-bırak: kök olmayan düğümü aynı ağaçta başka bir düğümün altına taşır (döngü olamaz).
    public function reparent(Request $request, Customer $customer, Organization $organization): JsonResponse
    {
        $data = $request->validate(['parent_id' => ['required', 'integer']]);
        $tree = $this->customerOrganizationService->tree($customer);
        $parentId = (int) $data['parent_id'];
        $node = $this->findNode($tree, (int) $organization->id);

        if ($node === null) {
            throw ValidationException::withMessages(['organization' => 'Seçilen organizasyon bu ana firmaya bağlı değil.']);
        }
        if ($organization->parent_id === null) {
            throw ValidationException::withMessages(['organization' => 'Firmanın en üstteki organizasyonu taşınamaz.']);
        }
        if (! $this->ids($tree)->contains($parentId)) {
            throw ValidationException::withMessages(['parent_id' => 'Seçilen üst organizasyon bu ana firmaya bağlı değil.']);
        }
        if ($this->ids([$node])->contains($parentId)) {
            throw ValidationException::withMessages(['parent_id' => 'Organizasyon kendi altına taşınamaz.']);
        }

        $organization->forceFill(['parent_id' => $parentId])->save();

        return response()->json(['id' => $organization->id, 'parent_id' => $parentId]);
    }

    // Ana firmanın ağacındaki bütün düğüm id'leri (tree() müşterinin tenant kontrolünü yapar).
    private function organizationIds(Customer $customer): Collection
    {
        return $this->ids($this->customerOrganizationService->tree($customer));
    }

    // Verilen düğümlerin ve altlarının id'leri.
    private function ids(array $nodes): Collection
    {
        $ids = collect();
        $walk = function (array $nodes) use (&$walk, $ids): void {
            foreach ($nodes as $node) {
                $ids->push((int) $node['id']);
                $walk($node['children'] ?? []);
            }
        };
        $walk($nodes);

        return $ids;
    }

    private function findNode(array $nodes, int $id): ?array
    {
        foreach ($nodes as $node) {
            if ((int) $node['id'] === $id) {
                return $node;
            }
            if ($found = $this->findNode($node['children'] ?? [], $id)) {
                return $found;
            }
        }

        return null;
    }
}
