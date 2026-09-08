<?php

namespace App\Domain\Tenancy;

/**
 * Header'daki aktif çalışma bağlamı (Organizasyon/Marka + Lokasyon) seçiminin
 * istek boyunca taşınması için TenantContext ile aynı desende bir singleton.
 *
 * ÖNEMLİ: Bu bir güvenlik sınırı DEĞİL (TenantScope öyledir) — kullanıcının
 * UI'da seçtiği bir varsayılan/filtredir. Bu yüzden hiçbir yerde model
 * seviyesinde global scope olarak kullanılmaz; sadece ilgili servisin liste
 * metodu bunu okuyup isteğe bağlı olarak sonucu daraltır. Header gönderilmezse
 * (çoğu çağrı, süper admin dahil) bu sınıf hiçbir şeye dokunmaz.
 */
class WorkspaceContext
{
    /** @var array<int, int>|null null = context seçilmemiş (filtre yok) */
    private ?array $allowedLocationIds = null;

    /** @var array<int, int>|null null = context seçilmemiş (filtre yok) */
    private ?array $allowedBusinessEntityIds = null;

    private ?int $selectedLocationId = null;

    public function setAllowedLocationIds(array $ids): void
    {
        $this->allowedLocationIds = array_values(array_unique(array_map('intval', $ids)));
    }

    public function hasOrganizationFilter(): bool
    {
        return $this->allowedLocationIds !== null;
    }

    /** @return array<int, int> */
    public function allowedLocationIds(): array
    {
        return $this->allowedLocationIds ?? [];
    }

    public function setAllowedBusinessEntityIds(array $ids): void
    {
        $this->allowedBusinessEntityIds = array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return array<int, int>|null */
    public function allowedBusinessEntityIds(): ?array
    {
        return $this->allowedBusinessEntityIds;
    }

    public function setSelectedLocationId(?int $id): void
    {
        $this->selectedLocationId = $id;
    }

    public function selectedLocationId(): ?int
    {
        return $this->selectedLocationId;
    }
}
