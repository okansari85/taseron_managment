<?php

namespace App\Http\Controllers;

use App\Models\PkAccountUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PkAccountService;
use App\Services\PkAccountUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// PKTakip OSGB / kurumsal hesap kullanıcıları (Ayarlar → Kullanıcılar): yalnızca hesabın aktif yöneticisi. Süper admin
// hesaptaki bir kullanıcı olarak girebilir (impersonate).
class PkAccountUserController extends Controller
{
    public function __construct(private PkAccountUserService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($this->service->managedTenant($request->user())));
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->service->managedTenant($request->user());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(PkAccountUser::ROLES)],
        ], ['email.unique' => 'Bu e-posta başka bir hesapta kayıtlı.']);

        $result = $this->service->invite($tenant, $data, $request->user());

        return response()->json([
            'message' => $result['mail_sent']
                ? 'Kullanıcı eklendi. Davet e-postası gönderildi.'
                : 'Kullanıcı eklendi ancak davet e-postası gönderilemedi. Daveti yeniden gönderebilirsiniz.',
            'user' => $result['user'],
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $tenant = $this->service->managedTenant($request->user());
        $data = $request->validate(['role' => ['required', Rule::in(PkAccountUser::ROLES)]]);

        return response()->json($this->service->updateRole($tenant, $user, $data['role']));
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $tenant = $this->service->managedTenant($request->user());

        return response()->json($this->service->deactivate($tenant, $user, $request->user()));
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $tenant = $this->service->managedTenant($request->user());

        return response()->json($this->service->activate($tenant, $user));
    }

    public function resendInvitation(Request $request, User $user): JsonResponse
    {
        $this->service->resendInvitation($this->service->managedTenant($request->user()), $user);

        return response()->json(['message' => 'Davet e-postası yeniden gönderildi.']);
    }

    // Süper admin: hesaptaki bir kullanıcı olarak gir.
    public function impersonate(Tenant $tenant, User $user): JsonResponse
    {
        abort_unless(in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true), 404);

        return response()->json($this->service->impersonate($tenant, $user));
    }
}
