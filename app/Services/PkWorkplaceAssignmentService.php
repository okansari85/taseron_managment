<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\LocationBusinessEntity;
use App\Models\LocationExpert;
use App\Models\PkAccountUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * OSGB / kurumsal hesapta uzmanı firmaya (işyeri: firma @ lokasyon) atama (Ayarlar → Firmalar): yönetici ve operasyon
 * yöneticisi yapar. Atama Taşeron'un location_experts kaydıdır (uzmanın Firmalarım listesi bundan). Yalnızca hesabın
 * aktif uzmanları atanır / çıkarılır; yönetici ve operasyon yöneticisinin kayıtlarına (işyeri eklerken otomatik) dokunulmaz.
 */
class PkWorkplaceAssignmentService
{
    public function __construct(
        private PkAccountService $accounts,
        private CustomerLocationService $customerLocations,
        private TenantContext $context
    ) {
    }

    // Atama yapabilen: OSGB / kurumsal hesabın aktif yöneticisi ya da operasyon yöneticisi. Hesap bağlamı kullanıcının kendi
    // hesabından kurulur (mevcut lokasyon servisleri bağlam ister).
    public function assignerTenant(User $actor): Tenant
    {
        $tenant = $this->accounts->tenantOf($actor);
        abort_unless($tenant && in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true), 403, 'Uzman ataması yalnızca OSGB ve kurumsal hesaplarda var.');
        abort_unless(in_array($this->accounts->roleOf($tenant, $actor), ['yonetici', 'operasyon'], true) && !$this->accounts->isPassive($tenant, $actor), 403, 'Uzman atamasını yönetici ve operasyon yöneticisi yapar.');
        $this->context->set($tenant);

        return $tenant;
    }

    // Yönetici ve operasyon yöneticisinin işyerleri: hesaptaki bütün işyerleri, uzmanın Firmalarım listesiyle (my-workplaces)
    // aynı biçimde (panelde Firmalarım, üst seçici ve Genel Bakış bundan). Uzman ve bireysel uzman my-workplaces'i kullanır.
    public function accountWorkplaces(Tenant $tenant): Collection
    {
        $context = [];
        foreach (Customer::query()->where('tenant_id', $tenant->id)->orderBy('name')->get() as $customer) {
            foreach ($this->customerLocations->list($customer) as $location) {
                $context[$location['id']] ??= ['customer_id' => $customer->id, 'customer_name' => $customer->name, 'organization_name' => $location['organization_name']];
            }
        }

        $items = LocationBusinessEntity::query()
            ->whereIn('location_id', DB::table('locations')->where('tenant_id', $tenant->id)->select('id'))
            ->whereHas('businessEntity', fn ($query) => $query->where('type', 'company'))
            ->with(['businessEntity.company', 'location:id,name'])
            ->orderByDesc('id')
            ->get();
        $experts = DB::table('location_experts')->join('users', 'users.id', '=', 'location_experts.user_id')
            ->whereIn('location_experts.location_business_entity_id', $items->pluck('id'))
            ->get(['location_experts.location_business_entity_id as workplace', 'users.name'])->groupBy('workplace');

        return $items->map(fn (LocationBusinessEntity $item) => [
            'id' => $item->id,
            'business_entity_id' => $item->business_entity_id,
            'company_name' => $item->businessEntity?->company?->name ?? $item->businessEntity?->name,
            'location_id' => $item->location_id,
            'location_name' => $item->location?->name,
            'customer_id' => $context[$item->location_id]['customer_id'] ?? null,
            'customer_name' => $context[$item->location_id]['customer_name'] ?? null,
            'organization_name' => $context[$item->location_id]['organization_name'] ?? null,
            'nace_code' => $item->nace_code,
            'activity' => $item->activity,
            'hazard_class' => $item->hazard_class,
            'sgk_workplace_number' => $item->sgk_workplace_number,
            'experts' => collect($experts[$item->id] ?? [])->pluck('name')->values(),
            'created_at' => $item->created_at,
        ])->values();
    }

    // Hesabın firmaları (atanan kullanıcılarıyla) ve atanabilecek aktif uzmanlar.
    public function list(Tenant $tenant): array
    {
        $members = $this->members($tenant);

        return [
            'workplaces' => $this->workplaces($tenant, $members),
            'experts' => $members->where('role', 'uzman')->where('passive', false)->values(),
        ];
    }

    // Firmanın uzmanları: verilen aktif uzmanlar atanır, listede olmayan uzmanların ataması kalkar.
    public function sync(Tenant $tenant, LocationBusinessEntity $workplace, array $userIds): array
    {
        abort_unless($this->belongsTo($tenant, $workplace), 404, 'Firma bu hesapta değil.');
        $members = $this->members($tenant);
        $experts = $members->where('role', 'uzman')->where('passive', false)->pluck('id')->all();
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        abort_if(array_diff($userIds, $experts), 422, 'Yalnızca hesabın aktif uzmanları atanabilir.');

        DB::transaction(function () use ($workplace, $userIds, $members) {
            $uzmanIds = $members->where('role', 'uzman')->pluck('id')->all();
            LocationExpert::query()->where('location_business_entity_id', $workplace->id)
                ->whereIn('user_id', $uzmanIds)->whereNotIn('user_id', $userIds)->delete();
            foreach ($userIds as $userId) {
                LocationExpert::query()->firstOrCreate(['location_business_entity_id' => $workplace->id, 'user_id' => $userId]);
            }
        });

        return $this->workplaces($tenant, $members, $workplace->id)->first();
    }

    // Hesabın panel kullanıcıları: ad, pktakip rolü, pasiflik.
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
                'email' => $user->email,
                'role' => $rows[$user->id]->role ?? ($user->hasRole('tenant') ? 'yonetici' : 'uzman'),
                'passive' => (bool) ($rows[$user->id]->deactivated_at ?? null),
            ]);
    }

    private function belongsTo(Tenant $tenant, LocationBusinessEntity $workplace): bool
    {
        return DB::table('locations')->where('id', $workplace->location_id)->where('tenant_id', $tenant->id)->exists();
    }

    private function workplaces(Tenant $tenant, Collection $members, ?int $onlyId = null): Collection
    {
        // Müşteri adı lokasyona göre (Firmalarım listesindeki gibi); yalnızca bu hesabın müşterileri.
        $customers = [];
        foreach (Customer::query()->where('tenant_id', $tenant->id)->orderBy('name')->get() as $customer) {
            foreach ($this->customerLocations->list($customer) as $location) {
                $customers[$location['id']] ??= $customer->name;
            }
        }
        $names = $members->keyBy('id');

        $items = LocationBusinessEntity::query()
            ->when($onlyId, fn ($query) => $query->whereKey($onlyId))
            ->whereIn('location_id', DB::table('locations')->where('tenant_id', $tenant->id)->select('id'))
            ->whereHas('businessEntity', fn ($query) => $query->where('type', 'company'))
            ->with(['businessEntity.company', 'location:id,name'])
            ->get();
        $assigned = LocationExpert::query()->whereIn('location_business_entity_id', $items->pluck('id'))->get()->groupBy('location_business_entity_id');

        return $items->map(fn (LocationBusinessEntity $item) => [
            'id' => $item->id,
            'company_name' => $item->businessEntity?->company?->name ?? $item->businessEntity?->name,
            'location_id' => $item->location_id,
            'location_name' => $item->location?->name,
            'customer_name' => $customers[$item->location_id] ?? null,
            // Atanan kullanıcılar (hesap dışı ya da silinmiş kullanıcılar gösterilmez).
            'users' => collect($assigned[$item->id] ?? [])
                ->map(fn (LocationExpert $row) => $names[$row->user_id] ?? null)
                ->filter()
                ->map(fn (array $user) => array_intersect_key($user, array_flip(['id', 'name', 'role', 'passive'])))
                ->values(),
        ])->sortBy(fn (array $item) => [$item['customer_name'] ?? '', $item['location_name'] ?? '', $item['company_name'] ?? ''])->values();
    }
}
