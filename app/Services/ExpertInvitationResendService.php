<?php

namespace App\Services;

use App\Mail\ExpertInvitationMail;
use App\Models\ExpertInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class ExpertInvitationResendService
{
    public function send(Tenant $tenant): void
    {
        abort_unless($tenant->tenant_type === 'expert', 404);

        $user = User::query()
            ->where('is_expert', true)
            ->whereHas('scopes', fn ($query) => $query
                ->where('scope_type', 'tenant')
                ->where('scope_id', $tenant->id))
            ->first();

        if (!$user) {
            throw new RuntimeException('Bu uzman için giriş kullanıcısı bulunamadı.');
        }

        $token = Str::random(64);

        $invitation = ExpertInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(48),
        ]);

        $acceptUrl = rtrim((string) env('FRONTEND_URL', env('APP_URL')), '/')
            . '/set-password?token=' . urlencode($token)
            . '&email=' . urlencode($user->email);

        try {
            Mail::to($user->email)->send(
                new ExpertInvitationMail($invitation->load('tenant'), $acceptUrl)
            );
        } catch (\Throwable $exception) {
            $invitation->delete();
            throw $exception;
        }
    }
}
