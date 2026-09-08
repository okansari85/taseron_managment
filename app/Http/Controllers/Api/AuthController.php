<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $user = Auth::user();
        $user->loadMissing('contractor.businessEntity');

        // bkz. routes/api.php /user endpoint'indeki aynı yorum - tenant_id
        // taşeron olmayan personel rolleri için UserScope('tenant')'tan gelir.
        $scopeTenantId = $user->scopes()->where('scope_type', 'tenant')->value('scope_id');

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'contractor_id' => $user->contractor_id,
                'contractor' => $user->contractor ? [
                    'id' => $user->contractor->id,
                    'name' => $user->contractor->businessEntity?->name,
                    'contractor_type' => $user->contractor->contractor_type,
                    'tenant_id' => $user->contractor->businessEntity?->tenant_id,
                ] : null,
                'tenant_id' => $scopeTenantId ? (int) $scopeTenantId : null,
            ],
        ]);
    }

    public function logout(): JsonResponse
    {
        request()->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }
}
