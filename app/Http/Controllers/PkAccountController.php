<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\PkAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// PKTakip hesapları: süper admin OSGB / kurumsal hesap açar, daveti yeniden gönderir, her hesap türüne (uzman dahil)
// girer; oturumdaki kullanıcı kendi hesabını (türünü) alır. Bireysel uzman açılışı ExpertController'da aynen kalır.
class PkAccountController extends Controller
{
    public function __construct(private PkAccountService $service)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(PkAccountService::MULTI_USER_TYPES)],
            'account_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $result = $this->service->create($data['type'], $data['account_name'], $data['contact_name'], $data['email']);

        return response()->json([
            'message' => $result['mail_sent']
                ? 'Hesap oluşturuldu. Davet e-postası gönderildi.'
                : 'Hesap oluşturuldu ancak davet e-postası gönderilemedi. Mail ayarlarını kontrol edin.',
            'data' => $result,
        ], 201);
    }

    public function impersonate(Tenant $tenant): JsonResponse
    {
        abort_unless(in_array($tenant->tenant_type, PkAccountService::TYPES, true), 404);

        return response()->json($this->service->impersonate($tenant));
    }

    public function resendInvitation(Tenant $tenant): JsonResponse
    {
        abort_unless(in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true), 404);
        $this->service->resendInvitation($tenant);

        return response()->json(['message' => 'Davet e-postası yeniden gönderildi.']);
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json($this->service->current($request->user()));
    }
}
