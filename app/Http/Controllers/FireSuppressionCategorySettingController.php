<?php

namespace App\Http\Controllers;

use App\Models\FireSuppressionInventoryItem;
use App\Services\FireSuppressionCategorySettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FireSuppressionCategorySettingController extends Controller
{
    public function __construct(
        private FireSuppressionCategorySettingService $service
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->all(),
        ]);
    }

    public function update(Request $request, string $category): JsonResponse
    {
        if (! in_array($category, FireSuppressionInventoryItem::CATEGORIES, true)) {
            abort(404);
        }

        $validated = $request->validate([
            'custom_label' => ['nullable', 'string', 'max:100'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $setting = $this->service->update($category, $validated);

        return response()->json([
            'message' => 'Ayar güncellendi.',
            'data' => [
                'category' => $setting->category,
                'custom_label' => $setting->custom_label,
                'is_enabled' => $setting->is_enabled,
            ],
        ]);
    }
}
