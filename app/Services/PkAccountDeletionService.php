<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * pktakip süper admin: hesabı pasife / aktife alma ve hesabı bütün kayıtlarıyla kalıcı silme (kullanıcı 19 Ekim 2026).
 * Silme yalnız pasif hesapta ve hesabın adı yazılarak yapılır. Hesaba bağlı tabloların hepsi tenants'a cascade bağlı; bağı
 * olmayan ya da silmeyi engelleyen birkaç tablo (companies, organizasyon üst bağı, katalog talepleri, özellik değerleri,
 * işyeri markaları) önce temizlenir. Yalnız bu hesaba ait kullanıcılar silinir (başka hesapta kapsamı olan ya da süper admin
 * olan kullanıcıya dokunulmaz). Dosyalar veritabanı silindikten sonra. Taşeron firmaları burada silinmez.
 */
class PkAccountDeletionService
{
    public function setActive(Tenant $tenant, bool $active): Tenant
    {
        $this->assertPk($tenant);
        $tenant->forceFill(['status' => $active])->save();

        return $tenant->fresh();
    }

    /** Silinecek kayıtların özeti (onay penceresi). */
    public function summary(Tenant $tenant): array
    {
        $this->assertPk($tenant);
        $locations = DB::table('locations')->where('tenant_id', $tenant->id)->pluck('id');

        return [
            'name' => $tenant->name,
            'type' => $tenant->tenant_type,
            'active' => (bool) $tenant->status,
            'customers' => DB::table('customers')->where('tenant_id', $tenant->id)->count(),
            'organizations' => DB::table('organizations')->where('tenant_id', $tenant->id)->count(),
            'locations' => $locations->count(),
            'workplaces' => DB::table('location_business_entities')->whereIn('location_id', $locations)->count(),
            'equipment' => DB::table('pk_equipment')->where('tenant_id', $tenant->id)->count(),
            'inspections' => DB::table('pk_inspections')->where('tenant_id', $tenant->id)->count(),
            'users' => $this->ownUsers($tenant)->count(),
        ];
    }

    public function delete(Tenant $tenant, string $confirmName): void
    {
        $this->assertPk($tenant);
        if ($tenant->status) {
            throw ValidationException::withMessages(['tenant' => 'Önce hesabı pasife alın.']);
        }
        if (trim($confirmName) !== trim($tenant->name)) {
            throw ValidationException::withMessages(['confirm_name' => 'Hesabın adı eşleşmiyor.']);
        }

        $locations = DB::table('locations')->where('tenant_id', $tenant->id)->pluck('id');
        $workplaces = DB::table('location_business_entities')->whereIn('location_id', $locations)->pluck('id');
        $equipment = DB::table('pk_equipment')->where('tenant_id', $tenant->id)->get(['id', 'photo_path']);
        $workplacePhotos = DB::table('location_business_entity_photos')->whereIn('location_business_entity_id', $workplaces)->pluck('photo_path');
        $entities = DB::table('business_entities')->where('tenant_id', $tenant->id)->pluck('id');
        $users = $this->ownUsers($tenant);
        $logo = $tenant->logo_path;

        DB::transaction(function () use ($tenant, $workplaces, $entities, $users) {
            // tenants'a bağı olmayan ya da silmeyi engelleyen kayıtlar
            DB::table('location_business_entity_brands')->whereIn('location_business_entity_id', $workplaces)->delete();
            DB::table('pk_equipment_property_values')->where('tenant_id', $tenant->id)->delete();
            DB::table('periodic_equipment_spec_requests')->where('tenant_id', $tenant->id)->delete();
            DB::table('companies')->whereIn('business_entity_id', $entities)->delete();
            DB::table('organizations')->where('tenant_id', $tenant->id)->update(['parent_id' => null]);

            // Yalnız bu hesaba ait kullanıcılar (oturumları, rolleri; kapsam / davet / uzman ataması cascade)
            if ($users->isNotEmpty()) {
                DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $users)->delete();
                DB::table('model_has_roles')->where('model_type', \App\Models\User::class)->whereIn('model_id', $users)->delete();
                DB::table('model_has_permissions')->where('model_type', \App\Models\User::class)->whereIn('model_id', $users)->delete();
                DB::table('users')->whereIn('id', $users)->delete();
            }

            // Hesap: bağlı bütün tablolar cascade
            DB::table('tenants')->where('id', $tenant->id)->delete();
        });

        // Dosyalar yalnızca veritabanı silindikten sonra (müşteri silmedeki gibi).
        $equipment->pluck('photo_path')->filter()->each(fn (string $path) => Storage::disk('public')->delete($path));
        $equipment->pluck('id')->each(fn ($id) => Storage::disk('local')->deleteDirectory("pk-inspections/{$id}"));
        $workplacePhotos->filter()->each(fn (string $path) => Storage::disk('public')->delete($path));
        if ($logo) {
            Storage::disk('public')->delete($logo);
        }
    }

    // Bu hesabın kapsamındaki kullanıcılardan başka bir hesapta kapsamı olmayan ve süper admin olmayanlar.
    private function ownUsers(Tenant $tenant): Collection
    {
        $ids = DB::table('user_scopes')->where('scope_type', 'tenant')->where('scope_id', $tenant->id)->pluck('user_id')
            ->merge(DB::table('pk_account_users')->where('tenant_id', $tenant->id)->pluck('user_id'))
            ->unique()->values();
        if ($ids->isEmpty()) {
            return $ids;
        }
        $elsewhere = DB::table('user_scopes')->whereIn('user_id', $ids)->where('scope_type', 'tenant')->where('scope_id', '!=', $tenant->id)->pluck('user_id');
        $admins = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'super-admin')->where('model_has_roles.model_type', \App\Models\User::class)->whereIn('model_id', $ids)->pluck('model_id');

        return $ids->diff($elsewhere)->diff($admins)->values();
    }

    private function assertPk(Tenant $tenant): void
    {
        abort_unless(in_array($tenant->tenant_type, PkAccountService::TYPES, true), 404);
    }
}
