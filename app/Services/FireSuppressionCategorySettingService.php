<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\FireSuppressionCategorySetting;
use App\Models\FireSuppressionInventoryItem;
use Illuminate\Support\Collection;
use LogicException;

class FireSuppressionCategorySettingService
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    // Sabit taksonomideki (FireSuppressionInventoryItem::CATEGORIES) HER
    // kategori için, bu tenant'ın varsa özelleştirdiği etiket/durumla
    // birleştirilmiş TEK bir liste döner — özelleştirme yoksa satır hiç
    // açılmaz, burada sadece varsayılan (custom_label: null, is_enabled:
    // true) görünür. Frontend bu listeyi doğrudan Ayarlar ekranında gösterir.
    public function all(): Collection
    {
        $overrides = FireSuppressionCategorySetting::query()
            ->get()
            ->keyBy('category');

        return collect(FireSuppressionInventoryItem::CATEGORIES)->map(function (string $category) use ($overrides) {
            $override = $overrides->get($category);

            return [
                'category' => $category,
                'custom_label' => $override?->custom_label,
                'is_enabled' => $override?->is_enabled ?? true,
            ];
        })->values();
    }

    public function update(string $category, array $data): FireSuppressionCategorySetting
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        return FireSuppressionCategorySetting::query()->updateOrCreate(
            ['tenant_id' => $this->tenantContext->id(), 'category' => $category],
            [
                'custom_label' => $data['custom_label'] ?? null,
                'is_enabled' => $data['is_enabled'] ?? true,
            ]
        );
    }
}
