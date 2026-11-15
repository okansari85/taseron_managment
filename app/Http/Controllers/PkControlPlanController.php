<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Models\PkControlPlan;
use App\Models\PkEquipment;
use App\Models\PkInstallation;
use App\Services\PkAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * pktakip kontrol planlama (2026-11-15). Kapsam Genel Bakış'taki gibi: lokasyon hesabın olmalı (TenantScope); işyeri, ekipman ve
 * tesisat o lokasyonun. Tenant bağlamı şart (yoksa global kapsam filtresiz kalır) ve kullanıcı o hesabın olmalı.
 */
class PkControlPlanController extends Controller
{
    private const RELATIONS = ['location:id,name', 'workplace.businessEntity.company', 'equipmentType:id,name', 'installation.type:id,name', 'creator:id,name'];

    public function __construct(private TenantContext $tenantContext, private PkAccountService $accounts)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeTenant($request);
        $data = $request->validate([
            'location_ids' => ['nullable', 'array', 'max:500'],
            'location_ids.*' => ['integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $plans = PkControlPlan::query()
            ->when(array_key_exists('location_ids', $data), fn ($query) => $query->whereIn('location_id', (array) $data['location_ids']))
            ->when($data['from'] ?? null, fn ($query, $date) => $query->where('date', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->where('date', '<=', $date))
            ->with(self::RELATIONS)->orderBy('date')->orderBy('id')->get();

        return response()->json($this->present($plans));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeTenant($request);
        $data = $this->assertPlacement($request->validate($this->rules(false)));
        $plan = PkControlPlan::query()->create($data + ['tenant_id' => $this->tenantContext->id(), 'created_by' => $request->user()->id]);

        return response()->json($this->present(collect([$plan->load(self::RELATIONS)]))[0], 201);
    }

    public function update(Request $request, PkControlPlan $pkControlPlan): JsonResponse
    {
        $this->authorizeTenant($request);
        // Gönderilmeyen alanlar kayıttakiyle doğrulanır (lokasyon değişirse ekipman / tesisat yeniden denetlenir).
        $current = $pkControlPlan->only(['location_id', 'workplace_id', 'equipment_ids', 'installation_id']);
        $data = $this->assertPlacement($request->validate($this->rules(true)) + $current);
        $pkControlPlan->update($data);

        return response()->json($this->present(collect([$pkControlPlan->refresh()->load(self::RELATIONS)]))[0]);
    }

    public function destroy(Request $request, PkControlPlan $pkControlPlan): Response
    {
        $this->authorizeTenant($request);
        $pkControlPlan->delete();

        return response()->noContent();
    }

    private function rules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'date' => [...$required, 'date_format:Y-m-d'],
            'location_id' => [...$required, 'integer'],
            'workplace_id' => ['nullable', 'integer'],
            'equipment_type_id' => ['nullable', 'integer', 'exists:periodic_equipment_types,id'],
            'equipment_ids' => ['nullable', 'array', 'max:500'],
            'equipment_ids.*' => ['integer', 'distinct'],
            'installation_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function assertPlacement(array $data): array
    {
        $locationId = (int) $data['location_id'];
        $ids = array_values(array_map('intval', (array) ($data['equipment_ids'] ?? [])));
        $errors = [];
        if (!Location::query()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Lokasyon bulunamadı.']);
        }
        if (!empty($data['workplace_id']) && !LocationBusinessEntity::query()->whereKey($data['workplace_id'])->where('location_id', $locationId)->exists()) {
            $errors['workplace_id'] = 'İşyeri bu lokasyona ait değil.';
        }
        if ($ids && PkEquipment::query()->whereKey($ids)->where('location_id', $locationId)->count() !== count($ids)) {
            $errors['equipment_ids'] = 'Ekipmanlar bu lokasyona ait olmalı.';
        }
        if (!empty($data['installation_id']) && !PkInstallation::query()->whereKey($data['installation_id'])->where('location_id', $locationId)->exists()) {
            $errors['installation_id'] = 'Tesisat bu lokasyona ait değil.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['equipment_ids' => $ids ?: null] + $data;
    }

    private function authorizeTenant(Request $request): void
    {
        abort_unless($this->tenantContext->has(), 403, 'Hesap seçilmedi.');
        $user = $request->user();
        abort_unless($user->hasRole('super-admin') || $this->accounts->tenantOf($user)?->id === $this->tenantContext->id(), 403, 'Bu hesaba erişiminiz yok.');
    }

    private function present(Collection $plans): array
    {
        $equipment = PkEquipment::query()->whereKey($plans->pluck('equipment_ids')->flatten()->filter()->unique()->values())
            ->with('type:id,name')->get()->keyBy('id');

        return $plans->map(function (PkControlPlan $plan) use ($equipment) {
            $business = $plan->workplace?->businessEntity;
            $installation = $plan->installation;

            return [
                'id' => $plan->id,
                'date' => $plan->date?->format('Y-m-d'),
                'title' => $plan->title,
                'note' => $plan->note,
                'location_id' => $plan->location_id,
                'location_name' => $plan->location?->name,
                'workplace_id' => $plan->workplace_id,
                'workplace_name' => $business?->company?->name ?? $business?->name,
                'equipment_type_id' => $plan->equipment_type_id,
                'equipment_type_name' => $plan->equipmentType?->name,
                'equipment_ids' => $plan->equipment_ids ?? [],
                // Silinmiş ekipman listede görünmez.
                'equipment' => collect($plan->equipment_ids ?? [])->map(fn ($id) => $equipment[$id] ?? null)->filter()
                    ->map(fn (PkEquipment $item) => ['id' => $item->id, 'name' => $item->name ?: $item->type?->name, 'code' => $item->code, 'type_name' => $item->type?->name])->values(),
                'installation_id' => $plan->installation_id,
                'installation' => $installation ? ['id' => $installation->id, 'name' => $installation->name ?: $installation->type?->name] : null,
                'created_by' => $plan->created_by,
                'created_by_name' => $plan->creator?->name,
                'created_at' => $plan->created_at?->toIso8601String(),
                'updated_at' => $plan->updated_at?->toIso8601String(),
            ];
        })->values()->all();
    }
}
