<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\PkUserProfile;
use App\Services\PkBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// pktakip: Kullanıcı Profili sayfası, bütün pktakip hesap türlerinde; kişisel alanlar her kullanıcının kendisi. Ad, e-posta ve kayıt tarihi hesaptan gelir, burada değişmez.
class PkUserProfileController extends Controller
{
    public function show(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $this->pktakipOnly($tenantContext);

        return response()->json($this->payload($request));
    }

    public function update(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $this->pktakipOnly($tenantContext);
        $data = $request->validate([
            'avatar' => ['nullable', Rule::in(['erkek', 'kadin'])],
            'phone' => ['nullable', 'string', 'max:30'],
            'profession' => ['nullable', Rule::in(['isg_uzmani', 'isyeri_hekimi', 'dsp'])],
            'expert_class' => ['nullable', 'required_if:profession,isg_uzmani', Rule::in(['A', 'B', 'C'])],
            'specialty' => ['nullable', 'string', 'max:150'],
            'employer' => ['nullable', 'string', 'max:150'],
        ]);
        // Sınıf yalnız iş güvenliği uzmanında anlamlı: meslek değişince eski sınıf kalmaz.
        if (($data['profession'] ?? null) !== 'isg_uzmani') {
            $data['expert_class'] = null;
        }
        PkUserProfile::query()->updateOrCreate(['user_id' => $request->user()->id], $data);

        return response()->json($this->payload($request));
    }

    private function pktakipOnly(TenantContext $tenantContext): void
    {
        abort_unless(in_array($tenantContext->get()->tenant_type, PkBillingService::ACCOUNT_TYPES, true), 403, 'Kullanıcı Profili yalnızca PKTakip hesaplarında var.');
    }

    private function payload(Request $request): array
    {
        $user = $request->user();
        $profile = PkUserProfile::query()->where('user_id', $user->id)->first();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at?->toDateString(),
            'avatar' => $profile?->avatar,
            'phone' => $profile?->phone,
            'profession' => $profile?->profession,
            'expert_class' => $profile?->expert_class,
            'specialty' => $profile?->specialty,
            'employer' => $profile?->employer,
        ];
    }
}
