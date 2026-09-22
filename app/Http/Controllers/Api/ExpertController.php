<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ExpertInvitationResendService;
use App\Services\SuperAdminExpertOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ExpertController extends Controller
{
    public function __construct(private SuperAdminExpertOnboardingService $service)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expert_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $result = $this->service->create(
            $data['expert_name'],
            $data['contact_name'],
            $data['email'],
        );

        return response()->json([
            'message' => $result['mail_sent']
                ? 'Uzman oluşturuldu. Davet e-postası gönderildi.'
                : 'Uzman oluşturuldu ancak davet e-postası gönderilemedi. Mail ayarlarını kontrol edin.',
            'data' => $result,
        ], 201);
    }

    public function impersonate(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($tenant->tenant_type === 'expert', 404);

        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('super-admin'), 403, 'Only super admin can impersonate users.');

        $user = User::query()
            ->where('is_expert', true)
            ->whereHas('scopes', fn ($query) => $query
                ->where('scope_type', 'tenant')
                ->where('scope_id', $tenant->id))
            ->first();

        abort_unless($user, 404, 'Bu uzman için giriş kullanıcısı bulunamadı.');

        $token = $user->createToken('impersonation')->plainTextToken;

        return response()->json([
            'message' => 'User impersonation started.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'contractor_id' => $user->contractor_id,
                'tenant_id' => $tenant->id,
            ],
        ]);
    }

    public function resendInvitation(Tenant $tenant, ExpertInvitationResendService $invitationService): JsonResponse
    {
        $invitationService->send($tenant);

        return response()->json([
            'message' => 'Uzman davet e-postası yeniden gönderildi.',
        ]);
    }

    public function setPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $this->service->setPassword(
            $data['email'],
            $data['token'],
            $data['password'],
        );

        return response()->json([
            'message' => 'Şifreniz oluşturuldu. Artık giriş yapabilirsiniz.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
