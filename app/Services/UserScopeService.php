<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\OperationalRegion;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\UserScopeRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class UserScopeService
{
    private const TYPES = ['tenant', 'organization', 'location', 'operational_region'];

    public function __construct(
        private UserScopeRepository $repository,
        private TenantContext $tenantContext,
    ) {
    }

    public function all(User $user): Collection
    {
        return $this->repository->all($user);
    }

    public function sync(User $user, array $scopes): Collection
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            $type = (string) ($scope['scope_type'] ?? '');
            $id = (int) ($scope['scope_id'] ?? 0);

            if (!in_array($type, self::TYPES, true) || $id < 1) {
                throw ValidationException::withMessages(['scopes' => 'Geçersiz scope tanımı.']);
            }

            $this->assertBelongsToTenant($type, $id);
            $normalized[] = ['scope_type' => $type, 'scope_id' => $id];
        }

        $this->repository->sync($user, $normalized);

        return $this->all($user);
    }

    public function attach(User $user, string $type, int $id): Collection
    {
        if (!in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['scope_type' => 'Geçersiz scope tipi.']);
        }

        $this->assertBelongsToTenant($type, $id);
        $this->repository->attach($user, $type, $id);

        return $this->all($user);
    }

    public function detach(User $user, string $type, int $id): Collection
    {
        $this->repository->detach($user, $type, $id);
        return $this->all($user);
    }

    // Taşeron olmayan personel rolleri için tenant_id, önceki halinde SADECE
    // açık bir scope_type='tenant' satırından okunuyordu. Bir kullanıcıya
    // Yetkilendirme ekranından sadece 'organization'/'location'/
    // 'operational_region' scope'u verilip ayrıca 'tenant' scope'u eklenmezse
    // (kolayca unutulabilecek bir adım), tenant_id null dönüyor ve frontend'de
    // X-Tenant-ID hiç set edilemediği için TenantScope filtresiz kalıp TÜM
    // tenant'ların verisi görünüyordu (bkz. Serkan/Beko lokasyon sızıntısı).
    // Burada 'tenant' satırı yoksa diğer scope tiplerinden (en genelden en
    // özele) tenant'a geriye doğru türetilir — süper admin gibi hiç scope'u
    // olmayan kullanıcılar için hâlâ null döner, davranışları değişmez.
    public function resolveTenantId(User $user): ?int
    {
        $scopes = $this->all($user);

        $tenantScopeId = $scopes->firstWhere('scope_type', 'tenant')?->scope_id;
        if ($tenantScopeId) {
            return (int) $tenantScopeId;
        }

        $organizationScopeId = $scopes->firstWhere('scope_type', 'organization')?->scope_id;
        if ($organizationScopeId) {
            $tenantId = Organization::query()->whereKey($organizationScopeId)->value('tenant_id');
            if ($tenantId) {
                return (int) $tenantId;
            }
        }

        $locationScopeId = $scopes->firstWhere('scope_type', 'location')?->scope_id;
        if ($locationScopeId) {
            $tenantId = Location::query()->whereKey($locationScopeId)->value('tenant_id');
            if ($tenantId) {
                return (int) $tenantId;
            }
        }

        $regionScopeId = $scopes->firstWhere('scope_type', 'operational_region')?->scope_id;
        if ($regionScopeId) {
            $tenantId = OperationalRegion::query()->whereKey($regionScopeId)->value('tenant_id');
            if ($tenantId) {
                return (int) $tenantId;
            }
        }

        return null;
    }

    private function assertBelongsToTenant(string $type, int $id): void
    {
        $tenantId = $this->tenantContext->id();

        $exists = match ($type) {
            'tenant' => Tenant::query()->whereKey($id)->exists(),
            'organization' => Organization::query()->whereKey($id)->exists(),
            'location' => Location::query()->whereKey($id)->exists(),
            'operational_region' => OperationalRegion::query()->whereKey($id)->exists(),
        };

        if (!$exists) {
            throw ValidationException::withMessages(['scope_id' => 'Scope mevcut tenant kapsamında değil.']);
        }

        if ($type === 'tenant' && $id !== $tenantId) {
            throw ValidationException::withMessages(['scope_id' => 'Scope mevcut tenant kapsamında değil.']);
        }
    }
}
