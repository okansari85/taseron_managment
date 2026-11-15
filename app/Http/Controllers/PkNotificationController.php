<?php

namespace App\Http\Controllers;

use App\Models\PkNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// pktakip panel bildirimleri (2026-11-15): yalnız oturumdaki kullanıcının bildirimleri; kiracı filtresi modelin global kapsamında.
class PkNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->mine($request)->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 20), ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (PkNotification $item) => $this->present($item))->values(),
            'unread_count' => $this->unreadCount($request),
            'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
        ]);
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        $item = $this->mine($request)->findOrFail($notification);
        $item->read_at ??= now();
        $item->save();

        return response()->json($this->present($item) + ['unread_count' => $this->unreadCount($request)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = $this->mine($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['updated' => $updated, 'unread_count' => 0]);
    }

    private function mine(Request $request)
    {
        return PkNotification::query()->where('user_id', $request->user()->id);
    }

    private function unreadCount(Request $request): int
    {
        return $this->mine($request)->whereNull('read_at')->count();
    }

    private function present(PkNotification $item): array
    {
        return [
            'id' => $item->id, 'type' => $item->type, 'title' => $item->title, 'body' => $item->body, 'link' => $item->link,
            'payload' => $item->payload, 'created_at' => $item->created_at?->toIso8601String(), 'read_at' => $item->read_at?->toIso8601String(),
        ];
    }
}
