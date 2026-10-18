<?php

namespace App\Http\Controllers;

use App\Services\PkAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Müşteri türü (pktakip, 2026-10-18): kurumsal (corporate), şahıs (individual), kamu (public). OSGB ve bireysel uzman müşteri
 * eklerken seçer; "Lokasyona Firma Ata" sihirbazı soruyu türe göre sorar. Mevcut müşteri uçlarına dokunmaz; yalnızca
 * customers.customer_type sütununu okur / yazar (hesabın kendi müşterileri). Kurumsal hesap kullanmaz.
 */
class PkCustomerTypeController extends Controller
{
    public const TYPES = ['corporate', 'individual', 'public'];

    public function __construct(private PkAccountService $accounts)
    {
    }

    // Hesabın müşteri türleri: müşteri kimliği → tür (boşsa null).
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->accounts->tenantOf($request->user());
        abort_unless($tenant, 403, 'Hesap bulunamadı.');

        $types = DB::table('customers')->where('tenant_id', $tenant->id)->pluck('customer_type', 'id');

        return response()->json((object) $types->all());
    }

    public function update(Request $request, int $customer): JsonResponse
    {
        $data = $request->validate(['type' => ['nullable', 'string', Rule::in(self::TYPES)]]);
        $tenant = $this->accounts->tenantOf($request->user());
        abort_unless($tenant, 403, 'Hesap bulunamadı.');
        // OSGB'de müşteri tanımlarını yönetici yapar; bireysel uzman kendi müşterisini değiştirir.
        abort_if(in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true) && $this->accounts->roleOf($tenant, $request->user()) !== 'yonetici', 403, 'Müşteri türünü yönetici değiştirir.');

        $query = DB::table('customers')->where('id', $customer)->where('tenant_id', $tenant->id);
        abort_unless($query->exists(), 404, 'Müşteri bulunamadı.');
        $query->update(['customer_type' => $data['type'] ?? null, 'updated_at' => now()]);

        return response()->json(['id' => $customer, 'type' => $data['type'] ?? null]);
    }
}
