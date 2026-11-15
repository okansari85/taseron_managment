<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Services\PkAccountService;
use App\Services\PkNotificationService;
use Illuminate\Console\Command;

/**
 * pktakip panel bildirimleri (2026-11-15), her gün (routes/console.php): PKTakip hesaplarının (Taşeron hesapları hariç) her biri
 * kendi tenant bağlamında işlenir. Bkz. PkNotificationService.
 */
class PkNotificationsGenerate extends Command
{
    protected $signature = 'pk:notifications-generate';

    protected $description = 'PKTakip panel bildirimlerini üretir (kontrol tarihleri, bulgu terminleri)';

    public function handle(TenantContext $tenantContext, PkNotificationService $service): int
    {
        $created = 0;
        foreach (Tenant::query()->whereIn('tenant_type', PkAccountService::TYPES)->orderBy('id')->get() as $tenant) {
            $tenantContext->set($tenant);
            // Bir hesabın hatası ötekileri durdurmaz (hata loglanır; yarın yeniden denenir).
            $created += (int) rescue(fn () => $service->generate($tenant), 0);
        }
        $tenantContext->clear();
        $this->info("{$created} bildirim üretildi.");

        return self::SUCCESS;
    }
}
