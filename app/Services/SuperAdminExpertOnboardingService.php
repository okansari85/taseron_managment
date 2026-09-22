<?php

namespace App\Services;

use App\Mail\ExpertInvitationMail;
use App\Models\ExpertInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\UserScopeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SuperAdminExpertOnboardingService
{
    public function __construct(private UserScopeRepository $scopes)
    {
    }

    public function create(string $expertName, string $contactName, string $email): array
    {
        $token = Str::random(64);

        [$tenant, $user, $invitation] = DB::transaction(function () use ($expertName, $contactName, $email, $token) {
            $tenant = Tenant::query()->create([
                'name' => trim($expertName),
                'slug' => $this->uniqueSlug($expertName),
                'tenant_type' => 'expert',
                'status' => true,
            ]);

            $user = User::query()->create([
                'name' => trim($contactName),
                'email' => trim($email),
                'password' => Hash::make(Str::random(64)),
                'is_expert' => true,
                'email_verified_at' => null,
            ]);
            $user->assignRole('tenant');

            // Super-admin onboarding must not depend on TenantContext.
            $this->scopes->attach($user, 'tenant', $tenant->id);

            $invitation = ExpertInvitation::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'email' => trim($email),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(48),
            ]);

            return [$tenant, $user, $invitation];
        });

        $acceptUrl = rtrim((string) env('FRONTEND_URL', env('APP_URL')), '/')
            . '/set-password?token=' . urlencode($token)
            . '&email=' . urlencode($email);

        $mailSent = true;
        try {
            Mail::to($email)->send(new ExpertInvitationMail($invitation->load('tenant'), $acceptUrl));
        } catch (\Throwable) {
            $mailSent = false;
        }

        return [
            'tenant' => $tenant,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'mail_sent' => $mailSent,
        ];
    }

    public function setPassword(string $email, string $token, string $password): User
    {
        $invitation = ExpertInvitation::query()
            ->where('email', $email)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->with('user')
            ->first();

        if (!$invitation || !$invitation->user) {
            throw ValidationException::withMessages([
                'token' => 'Davetiye bağlantısı geçersiz veya süresi dolmuş.',
            ]);
        }

        DB::transaction(function () use ($invitation, $password) {
            $invitation->user->update([
                'password' => $password,
                'email_verified_at' => now(),
            ]);
            $invitation->update(['used_at' => now()]);
        });

        return $invitation->user->fresh();
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
