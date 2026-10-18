<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\PkAccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// pktakip süper admin: hesabı pasife / aktife alma, silme özeti ve kalıcı silme (yalnız pasif hesapta, adı yazılarak).
class PkAccountStatusController extends Controller
{
    public function __construct(private PkAccountDeletionService $service)
    {
    }

    public function deactivate(Tenant $tenant): JsonResponse
    {
        $this->service->setActive($tenant, false);

        return response()->json(['message' => 'Hesap pasife alındı. Kullanıcıları giriş yapamaz.', 'status' => false]);
    }

    public function activate(Tenant $tenant): JsonResponse
    {
        $this->service->setActive($tenant, true);

        return response()->json(['message' => 'Hesap aktife alındı.', 'status' => true]);
    }

    public function summary(Tenant $tenant): JsonResponse
    {
        return response()->json($this->service->summary($tenant));
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['confirm_name' => ['required', 'string', 'max:255']]);
        $this->service->delete($tenant, $data['confirm_name']);

        return response()->json(['message' => 'Hesap ve bütün kayıtları silindi.']);
    }
}
