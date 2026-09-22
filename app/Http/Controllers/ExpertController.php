<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\ExpertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class ExpertController extends Controller
{
    public function __construct(private ExpertService $service)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expert_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $result = $this->service->create($data);

        return response()->json($result, $result['mail_sent'] ? 201 : 201);
    }

    public function setPassword(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = $this->service->setPassword($credentials);

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => match ($status) {
                    Password::INVALID_TOKEN => 'Davet bağlantısı geçersiz veya süresi dolmuş.',
                    Password::INVALID_USER => 'Kullanıcı bulunamadı.',
                    default => 'Şifre oluşturulamadı.',
                },
            ], 422);
        }

        return response()->json([
            'message' => 'Şifreniz başarıyla oluşturuldu. Artık giriş yapabilirsiniz.',
        ]);
    }

    public function impersonate(Tenant $tenant): JsonResponse
    {
        return response()->json($this->service->impersonate($tenant));
    }
}
