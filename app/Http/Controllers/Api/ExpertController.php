<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExpertOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ExpertController extends Controller
{
    public function __construct(private ExpertOnboardingService $service)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expert_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        return response()->json([
            'message' => 'Uzman oluşturuldu. Davet e-postası gönderildi.',
            'data' => $this->service->create(
                $data['expert_name'],
                $data['contact_name'],
                $data['email'],
            ),
        ], 201);
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
