<?php

namespace App\Http\Controllers;

use App\Models\BusinessEntity;
use App\Services\PkCompanyTitleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Firma ünvanları (Ayarlar → Firmalar, Lokasyon Firmaları): liste ve firmanın ünvanı.
class PkCompanyTitleController extends Controller
{
    public function __construct(private PkCompanyTitleService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($request->user()));
    }

    public function update(Request $request, int $businessEntity): JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:255']]);
        // Hesap bağlamı olmadan TenantScope süzmez; hesap kontrolü serviste.
        $entity = BusinessEntity::query()->withoutGlobalScopes()->findOrFail($businessEntity);

        return response()->json(['title' => $this->service->assign($request->user(), $entity, $data['title'] ?? null)]);
    }
}
