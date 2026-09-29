<?php

namespace App\Services;

use App\Models\BusinessEntity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Firma ünvanları (ör. "Arçelik A.Ş."): hesapta bir kez tanımlanır, firmalar (ör. aynı şirketin işletmeleri) ona bağlanır.
 * Ünvan yazımla eşleşir: "Arçelik AŞ" ile "arçelik a.ş" aynı ünvan (firma adlarındaki mükerrer kontrolü gibi).
 * Değiştirme: bireysel uzman hesabında uzman, OSGB / kurumsal hesapta yalnızca yönetici (firma tanımları yapıdır).
 */
class PkCompanyTitleService
{
    public function __construct(private PkAccountService $accounts)
    {
    }

    // Hesabın ünvanları (bağlı firma sayısıyla) ve firma → ünvan eşleşmesi.
    public function list(User $user): array
    {
        $tenant = $this->tenant($user);
        $titles = DB::table('pk_company_titles')->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name']);
        $links = DB::table('pk_company_title_links')->whereIn('pk_company_title_id', $titles->pluck('id'))->pluck('pk_company_title_id', 'business_entity_id');
        $counts = $links->countBy();

        return [
            'titles' => $titles->map(fn ($title) => ['id' => $title->id, 'name' => $title->name, 'companies' => (int) ($counts[$title->id] ?? 0)])->values(),
            'links' => $links,
        ];
    }

    // Firmanın ünvanı: boşsa bağ kalkar; yazılan ünvan hesapta (yazımdan bağımsız) varsa ona, yoksa yeni tanımlanıp bağlanır.
    public function assign(User $user, BusinessEntity $businessEntity, ?string $name): ?array
    {
        $tenant = $this->tenant($user);
        abort_unless((int) DB::table('business_entities')->where('id', $businessEntity->id)->value('tenant_id') === $tenant->id, 404, 'Firma bu hesapta değil.');
        abort_if(in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true) && $this->accounts->roleOf($tenant, $user) !== 'yonetici', 403, 'Firma ünvanını yönetici değiştirir.');

        $name = trim((string) $name);
        if ($name === '') {
            DB::table('pk_company_title_links')->where('business_entity_id', $businessEntity->id)->delete();

            return null;
        }

        return DB::transaction(function () use ($tenant, $businessEntity, $name) {
            $key = $this->key($name);
            $title = DB::table('pk_company_titles')->where('tenant_id', $tenant->id)->get(['id', 'name'])->first(fn ($row) => $this->key($row->name) === $key);
            $titleId = $title?->id ?? DB::table('pk_company_titles')->insertGetId(['tenant_id' => $tenant->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('pk_company_title_links')->updateOrInsert(
                ['business_entity_id' => $businessEntity->id],
                ['pk_company_title_id' => $titleId, 'updated_at' => now(), 'created_at' => now()]
            );

            return ['id' => $titleId, 'name' => $title?->name ?? $name];
        });
    }

    private function tenant(User $user): Tenant
    {
        $tenant = $this->accounts->tenantOf($user);
        abort_unless($tenant, 403, 'Hesap bulunamadı.');

        return $tenant;
    }

    // Yazımdan bağımsız karşılaştırma anahtarı: "Arçelik A.Ş." = "arçelik aş" = "arcelikas".
    private function key(string $name): string
    {
        return Str::slug(str_replace(['İ', 'I'], ['i', 'ı'], $name), '');
    }
}
