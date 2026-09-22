<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExpertInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class ExpertService
{
    public function __construct(private UserScopeService $scopeService)
    {
    }

    public function create(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
            $tenant = Tenant::query()->create([
                'name' => trim($data['expert_name']),
                'slug' => $this->uniqueSlug($data['expert_name']),
                'tenant_type' => 'expert',
                'status' => true,
            ]);

            $user = User::query()->create([
                'name' => trim($data['contact_name']),
                'email' => trim($data['email']),
                'password' => Str::random(48),
                'is_expert' => true,
            ]);

            $user->assignRole('isg');
            $this->scopeService->attach($user, 'tenant', $tenant->id);

            $token = Password::broker()->createToken($user);

            return compact('tenant', 'user', 'token');
        });

        try {
            Notification::route('mail', $result['user']->email)
                ->notify(new ExpertInvitationNotification($result['token']));
        } catch (Throwable $exception) {
            report($exception);

            return [
                'tenant' => $result['tenant'],
                'user' => $result['user'],
                'mail_sent' => false,
                'message' => 'Uzman oluşturuldu ancak davet e-postası gönderilemedi. Mail ayarlarını kontrol edin.',
            ];
        }

        return [
            'tenant' => $result['tenant'],
            'user' => $result['user'],
            'mail_sent' => true,
            'message' => 'Uzman oluşturuldu ve davet e-postası gönderildi.',
        ];
    }

    public function setPassword(array $credentials): string
    {
        $status = Password::broker()->reset(
            $credentials,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $status;
        }

        return Password::PASSWORD_RESET;
    }

    public function impersonate(Tenant $tenant): array
    {
        abort_unless($tenant->tenant_type === 'expert', 404, 'Uzman bulunamadı.');

        $user = User::query()
            ->where('is_expert', true)
            ->whereHas('scopes', function ($query) use ($tenant): void {
                $query->where('scope_type', 'tenant')
                    ->where('scope_id', $tenant->id);
            })
            ->firstOrFail();

        $token = $user->createToken('impersonation')->plainTextToken;

        return [
            'message' => 'Uzman oturumu başlatıldı.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'tenant_id' => $tenant->id,
            ],
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'uzman';
        $slug = $base;
        $counter = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }
}
