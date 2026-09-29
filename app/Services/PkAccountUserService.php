<?php

namespace App\Services;

use App\Mail\PkUserInvitationMail;
use App\Models\PkAccountUser;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\UserScopeRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * PKTakip OSGB / kurumsal hesap kullanıcıları (Ayarlar → Kullanıcılar): hesabın yöneticisi davet eder, rolünü değiştirir,
 * pasife / aktife alır. Rol pk_account_users'ta (yonetici | operasyon | uzman); Taşeron rolü yönetici için tenant,
 * diğerleri için isg (pktakip uçlarına girebilen roller). Pasif kullanıcı giriş yapamaz (şifresi geçersiz, oturumları
 * kapanır), uzman atamaları kalkar; verisi silinmez. Bireysel uzman hesaplarında kullanılmaz.
 */
class PkAccountUserService
{
    public const ROLE_LABELS = ['yonetici' => 'Yönetici', 'operasyon' => 'Operasyon yöneticisi', 'uzman' => 'Uzman'];

    public function __construct(
        private PkAccountService $accounts,
        private UserScopeRepository $scopes
    ) {
    }

    // Oturumdaki kullanıcının yönettiği hesap: OSGB / kurumsal ve aktif yönetici değilse 403.
    public function managedTenant(User $actor): Tenant
    {
        $tenant = $this->accounts->tenantOf($actor);
        abort_unless($tenant && in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true), 403, 'Kullanıcı yönetimi yalnızca OSGB ve kurumsal hesaplarda var.');
        abort_unless($this->accounts->roleOf($tenant, $actor) === 'yonetici' && !$this->accounts->isPassive($tenant, $actor), 403, 'Kullanıcıları yalnızca hesabın yöneticisi yönetir.');

        return $tenant;
    }

    public function list(Tenant $tenant): Collection
    {
        $rows = PkAccountUser::query()->where('tenant_id', $tenant->id)->get()->keyBy('user_id');
        $workplaces = DB::table('location_experts')->whereIn('user_id', $this->members($tenant)->pluck('id'))
            ->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');

        return $this->members($tenant)
            ->map(fn (User $user) => $this->present($user, $rows[$user->id] ?? null, (int) ($workplaces[$user->id] ?? 0)))
            ->sortBy(fn (array $item) => [$item['status'] === 'pasif' ? 1 : 0, array_search($item['role'], PkAccountUser::ROLES, true), $item['name']])
            ->values();
    }

    public function invite(Tenant $tenant, array $data, User $actor): array
    {
        $token = Str::random(64);

        [$user, $invitation] = DB::transaction(function () use ($tenant, $data, $actor, $token) {
            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => trim($data['email']),
                'password' => Hash::make(Str::random(64)),
                'is_expert' => true,
                'email_verified_at' => null,
            ]);
            $user->assignRole($this->systemRole($data['role']));
            $this->scopes->attach($user, 'tenant', $tenant->id);
            PkAccountUser::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $data['role'], 'created_by' => $actor->id]);

            return [$user, $this->accounts->invitation($tenant, $user, $token)];
        });

        $mailSent = true;
        try {
            $this->mail($invitation, $token, $data['role']);
        } catch (\Throwable) {
            $mailSent = false;
        }

        return ['user' => $this->present($user, $this->row($tenant, $user), 0), 'mail_sent' => $mailSent];
    }

    public function updateRole(Tenant $tenant, User $user, string $role): array
    {
        $this->assertMember($tenant, $user);
        if ($role !== 'yonetici') {
            $this->assertAnotherManager($tenant, $user, 'Hesapta en az bir aktif yönetici kalmalı.');
        }

        DB::transaction(function () use ($tenant, $user, $role) {
            $user->syncRoles([$this->systemRole($role)]);
            PkAccountUser::query()->updateOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id], ['role' => $role]);
        });

        return $this->present($user->fresh(), $this->row($tenant, $user), $this->workplaceCount($user));
    }

    public function deactivate(Tenant $tenant, User $user, User $actor): array
    {
        $this->assertMember($tenant, $user);
        abort_if($user->id === $actor->id, 422, 'Kendinizi pasife alamazsınız.');
        $this->assertAnotherManager($tenant, $user, 'Hesabın son aktif yöneticisi pasife alınamaz.');

        DB::transaction(function () use ($tenant, $user) {
            PkAccountUser::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => $this->accounts->roleOf($tenant, $user), 'deactivated_at' => now()]
            );
            // Giriş kapanır: şifre geçersiz, açık oturumlar silinir; aktife alınınca yeni davetle şifre belirlenir.
            $user->forceFill(['password' => Hash::make(Str::random(64)), 'email_verified_at' => null])->save();
            $user->tokens()->delete();
            // Uzman atamaları kalkar (firmalar uzmansız kalır, başka uzmana atanır).
            DB::table('location_experts')->where('user_id', $user->id)->delete();
        });

        return $this->present($user->fresh(), $this->row($tenant, $user), 0);
    }

    // Aktife alma: yeni davet (şifre belirleme); davet gönderilemezse kullanıcı pasif kalır.
    public function activate(Tenant $tenant, User $user): array
    {
        $this->assertMember($tenant, $user);
        $row = $this->row($tenant, $user);
        $this->resend($tenant, $user);
        $row?->update(['deactivated_at' => null]);

        return $this->present($user->fresh(), $this->row($tenant, $user), $this->workplaceCount($user));
    }

    public function resendInvitation(Tenant $tenant, User $user): void
    {
        $this->assertMember($tenant, $user);
        abort_if($this->accounts->isPassive($tenant, $user), 422, 'Pasif kullanıcıya davet gönderilmez; önce aktife alın.');
        $this->resend($tenant, $user);
    }

    // Süper admin hesaptaki bir kullanıcı olarak girer (her rolün panelini görmek için); pasif kullanıcıya girilmez.
    public function impersonate(Tenant $tenant, User $user): array
    {
        $this->assertMember($tenant, $user);
        abort_if($this->accounts->isPassive($tenant, $user), 422, 'Pasif kullanıcı olarak girilemez.');

        return [
            'token' => $user->createToken('impersonation')->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'is_expert' => (bool) $user->is_expert,
                'tenant_id' => $tenant->id,
            ],
        ];
    }

    // Hesabın kullanıcıları: hesap kapsamı (tenant) olan panel kullanıcıları.
    private function members(Tenant $tenant): Collection
    {
        return User::query()
            ->where('is_expert', true)
            ->whereHas('scopes', fn ($query) => $query->where('scope_type', 'tenant')->where('scope_id', $tenant->id))
            ->orderBy('name')
            ->get();
    }

    private function assertMember(Tenant $tenant, User $user): void
    {
        abort_unless($this->members($tenant)->contains('id', $user->id), 404, 'Kullanıcı bu hesapta değil.');
    }

    // Hedef kullanıcı dışında aktif bir yönetici kalmalı (son yönetici pasife alınamaz, rolü düşürülemez).
    private function assertAnotherManager(Tenant $tenant, User $user, string $message): void
    {
        $others = $this->members($tenant)->reject(fn (User $member) => $member->id === $user->id)
            ->filter(fn (User $member) => $this->accounts->roleOf($tenant, $member) === 'yonetici' && !$this->accounts->isPassive($tenant, $member));
        abort_if($this->accounts->roleOf($tenant, $user) === 'yonetici' && $others->isEmpty(), 422, $message);
    }

    private function resend(Tenant $tenant, User $user): void
    {
        $token = Str::random(64);
        $invitation = $this->accounts->invitation($tenant, $user, $token);

        try {
            $this->mail($invitation, $token, $this->accounts->roleOf($tenant, $user));
        } catch (\Throwable $exception) {
            $invitation->delete();
            throw $exception;
        }
    }

    private function mail($invitation, string $token, string $role): void
    {
        Mail::to($invitation->email)->send(new PkUserInvitationMail($invitation->load('tenant'), $this->accounts->acceptUrl($invitation, $token), self::ROLE_LABELS[$role] ?? 'Uzman'));
    }

    // Taşeron rolü: yönetici tenant; operasyon yöneticisi ve uzman isg (pktakip uçlarına girebilen rol).
    private function systemRole(string $role): string
    {
        return $role === 'yonetici' ? 'tenant' : 'isg';
    }

    private function row(Tenant $tenant, User $user): ?PkAccountUser
    {
        return PkAccountUser::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
    }

    private function workplaceCount(User $user): int
    {
        return DB::table('location_experts')->where('user_id', $user->id)->count();
    }

    private function present(User $user, ?PkAccountUser $row, int $workplaces): array
    {
        $role = $row?->role ?? ($user->hasRole('tenant') ? 'yonetici' : 'uzman');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role,
            'role_label' => self::ROLE_LABELS[$role] ?? $role,
            // pasif | davet (şifresini henüz belirlemedi) | aktif
            'status' => $row?->deactivated_at ? 'pasif' : ($user->email_verified_at ? 'aktif' : 'davet'),
            'workplaces_count' => $workplaces,
            'deactivated_at' => $row?->deactivated_at?->format('Y-m-d'),
            'created_at' => $user->created_at?->format('Y-m-d'),
        ];
    }
}
