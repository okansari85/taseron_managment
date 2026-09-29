<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\LocationBusinessEntity;
use App\Models\LocationExpert;
use App\Models\PkAccountUser;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Genel Bakış'ta OSGB / kurumsal hesabın yöneticisi ve operasyon yöneticisinin ek bölümleri: uzman bazında durum, müşteri
 * bazında durum (OSGB), uzmanı olmayan firmalar, bu ayki okuma (kontür) kullanımı. Ekipman PkEquipmentService::list ile
 * (Genel Bakış'la aynı liste), skor Genel Bakış'taki tanımla: tamam = süresi geçmemiş ve son kontrolü uygun ya da
 * belirtilmemiş. Uzmanın ekipmanı: atandığı firmaların ekipmanı ve o lokasyonların lokasyon geneli ekipmanı.
 */
class PkAccountOverviewService
{
    public function __construct(
        private PkAccountService $accounts,
        private PkEquipmentService $equipment,
        private CustomerLocationService $customerLocations,
        private TenantContext $context
    ) {
    }

    // Görebilen: OSGB / kurumsal hesabın aktif yöneticisi ya da operasyon yöneticisi.
    public function viewerTenant(User $actor): Tenant
    {
        $tenant = $this->accounts->tenantOf($actor);
        abort_unless($tenant && in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true), 403, 'Bu özet yalnızca OSGB ve kurumsal hesaplarda var.');
        abort_unless(in_array($this->accounts->roleOf($tenant, $actor), ['yonetici', 'operasyon'], true) && !$this->accounts->isPassive($tenant, $actor), 403, 'Bu özeti yönetici ve operasyon yöneticisi görür.');
        $this->context->set($tenant);

        return $tenant;
    }

    // $locationIds: sayfanın kapsamı (filtre / il); boşsa hesabın tüm lokasyonları.
    public function overview(Tenant $tenant, array $locationIds): array
    {
        $tenantLocations = DB::table('locations')->where('tenant_id', $tenant->id)->pluck('id')->all();
        $scope = $locationIds ? array_values(array_intersect($tenantLocations, $locationIds)) : $tenantLocations;
        $active = ($scope ? $this->equipment->list(['location_ids' => $scope]) : collect())->where('is_active', true)->values();

        $members = $this->members($tenant);
        $workplaces = LocationBusinessEntity::query()
            ->whereIn('location_id', $scope ?: [0])
            ->whereHas('businessEntity', fn ($query) => $query->where('type', 'company'))
            ->with(['businessEntity.company', 'location:id,name'])
            ->get();
        $assignments = LocationExpert::query()->whereIn('location_business_entity_id', $workplaces->pluck('id'))->get();
        $uzmanIds = $members->where('role', 'uzman')->pluck('id')->all();
        $customerOf = $this->customerNames($tenant);

        $experts = $members->where('role', 'uzman')->where('passive', false)->map(function (array $member) use ($assignments, $workplaces, $active) {
            $mine = $workplaces->whereIn('id', $assignments->where('user_id', $member['id'])->pluck('location_business_entity_id'));
            $locationIds = $mine->pluck('location_id')->unique()->values()->all();
            $items = $active->filter(fn ($item) => in_array($item['workplace']['id'] ?? null, $mine->pluck('id')->all(), true)
                || (empty($item['workplace']) && in_array($item['location']['id'] ?? null, $locationIds, true)));

            return $member + ['workplaces' => $mine->count(), 'locations' => count($locationIds), 'score' => $this->scoreOf($items)];
        })->sortBy(fn ($row) => [-($row['score']['overdue']), $row['name']])->values();

        $unassigned = $workplaces->reject(fn (LocationBusinessEntity $item) => $assignments->where('location_business_entity_id', $item->id)->whereIn('user_id', $uzmanIds)->isNotEmpty())
            ->map(fn (LocationBusinessEntity $item) => [
                'id' => $item->id,
                'company_name' => $item->businessEntity?->company?->name ?? $item->businessEntity?->name,
                'location_id' => $item->location_id,
                'location_name' => $item->location?->name,
                'customer_name' => $customerOf[$item->location_id] ?? null,
            ])->sortBy(fn ($row) => [$row['customer_name'] ?? '', $row['location_name'] ?? '', $row['company_name'] ?? ''])->values();

        return [
            'counts' => [
                'experts' => $members->where('role', 'uzman')->where('passive', false)->count(),
                'workplaces' => $workplaces->count(),
                'unassigned' => $unassigned->count(),
            ],
            'experts' => $experts,
            'customers' => $tenant->tenant_type === 'osgb' ? $this->customers($tenant, $active, $scope) : [],
            'unassigned' => $unassigned,
            'usage' => $this->usage($tenant, $members),
        ];
    }

    // OSGB: müşteri bazında durum (müşterinin lokasyonlarındaki ekipman).
    private function customers(Tenant $tenant, Collection $active, array $scope): Collection
    {
        return Customer::query()->where('tenant_id', $tenant->id)->orderBy('name')->get()->map(function (Customer $customer) use ($active, $scope) {
            $locationIds = collect($this->customerLocations->list($customer))->pluck('id')->intersect($scope)->values()->all();
            $items = $active->filter(fn ($item) => in_array($item['location']['id'] ?? null, $locationIds, true));

            return ['id' => $customer->id, 'name' => $customer->name, 'locations' => count($locationIds), 'score' => $this->scoreOf($items)];
        })->filter(fn ($row) => $row['locations'] > 0)->sortBy(fn ($row) => [-($row['score']['overdue']), $row['name']])->values();
    }

    // Bu ayın rapor okumaları (başarısızlar sayılmaz; kontür bunlardan düşülecek), kullanıcı bazında.
    private function usage(Tenant $tenant, Collection $members): array
    {
        $start = CarbonImmutable::today()->startOfMonth();
        $rows = DB::table('pk_report_analyses')->where('tenant_id', $tenant->id)->where('status', '!=', 'failed')
            ->where('created_at', '>=', $start)->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');
        $names = $members->keyBy('id');

        return [
            'month' => $start->format('Y-m'),
            'total' => (int) $rows->sum(),
            'users' => $rows->map(fn ($total, $userId) => [
                'id' => (int) $userId,
                'name' => $names[$userId]['name'] ?? 'Silinmiş kullanıcı',
                'role' => $names[$userId]['role'] ?? null,
                'total' => (int) $total,
            ])->sortByDesc('total')->values(),
        ];
    }

    // Lokasyon → müşteri adı (yalnızca bu hesabın müşterileri).
    private function customerNames(Tenant $tenant): array
    {
        $names = [];
        foreach (Customer::query()->where('tenant_id', $tenant->id)->orderBy('name')->get() as $customer) {
            foreach ($this->customerLocations->list($customer) as $location) {
                $names[$location['id']] ??= $customer->name;
            }
        }

        return $names;
    }

    private function members(Tenant $tenant): Collection
    {
        $rows = PkAccountUser::query()->where('tenant_id', $tenant->id)->get()->keyBy('user_id');

        return User::query()
            ->where('is_expert', true)
            ->whereHas('scopes', fn ($query) => $query->where('scope_type', 'tenant')->where('scope_id', $tenant->id))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $rows[$user->id]->role ?? ($user->hasRole('tenant') ? 'yonetici' : 'uzman'),
                'passive' => (bool) ($rows[$user->id]->deactivated_at ?? null),
            ]);
    }

    // Genel Bakış skoru: yüzde kaçı tamam; tamam olmayan her ekipman tek nedende (süresi geçmiş, uygun değil, rapor
    // bekleniyor, rapor yok).
    private function scoreOf(Collection $items): array
    {
        $counts = $items->countBy(fn ($item) => match (true) {
            $item['days_left'] !== null && $item['days_left'] < 0 => 'overdue',
            $item['status'] === 'uygun_degil' => 'nonconforming',
            $item['status'] === 'rapor_bekleniyor' => 'pending',
            $item['status'] === 'rapor_yok' => 'no_report',
            default => 'ok',
        });
        $total = $items->count();

        return [
            'total' => $total,
            'ok' => $counts['ok'] ?? 0,
            'percent' => $total ? floor((($counts['ok'] ?? 0) / $total) * 1000) / 10 : null,
            'overdue' => $counts['overdue'] ?? 0,
            'nonconforming' => $counts['nonconforming'] ?? 0,
        ];
    }
}
