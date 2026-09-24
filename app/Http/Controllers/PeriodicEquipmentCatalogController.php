<?php

namespace App\Http\Controllers;

use App\Models\PeriodicEquipmentCategory;
use Illuminate\Http\JsonResponse;

// pktakip ekipman kataloğu: ön tanımlı kategoriler ve türleri (salt okunur, tüm kullanıcılarda ortak).
class PeriodicEquipmentCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $categories = PeriodicEquipmentCategory::query()
            ->where('is_active', true)
            ->with(['types' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PeriodicEquipmentCategory $category) => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
                'kind' => $category->kind,
                'icon' => $category->icon,
                'types' => $category->types->map(fn ($type) => [
                    'id' => $type->id,
                    'slug' => $type->slug,
                    'name' => $type->name,
                    'default_period_months' => $type->default_period_months,
                    'default_scope' => $type->default_scope,
                    'regulation_note' => $type->regulation_note,
                ])->values(),
            ])
            ->values();

        return response()->json($categories);
    }
}
