<?php

namespace App\Services;

use App\Mail\PkAccountInvitationMail;
use App\Models\Customer;
use App\Models\ExpertInvitation;
use App\Models\PkAccountUser;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\UserScopeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * PKTakip hesapları (süper admin). Çok kullanıcılı OSGB ve kurumsal firma hesapları burada açılır: açılan kullanıcı hesabın
 * yöneticisidir (rol tenant), davet e-postasıyla kendi şifresini belirler (şifre belirleme uzman davetiyle aynı:
 * experts/set-password); kurumsal hesapta firmanın kendisi tek müşteri olarak açılır. Bireysel uzman hesapları bitti:
 * açılışı ve daveti Taşeron uzman uçlarıyla (ExpertController) aynen kalır; burada yalnızca süper admin girişi var.
 */
class PkAccountService
{
    // Süper adminin girebildiği hesaplar.
    public const TYPES = ['expert', 'osgb', 'corporate'];

    // Burada açılan, birden çok kullanıcılı hesaplar.
    public const MULTI_USER_TYPES = ['osgb', 'corporate'];

    public function __construct(
        private UserScopeRepository $scopes,
        private UserScopeService $userScopes
    ) {
    }

    public function create(string $type, string $accountName, string $contactName, string $email): array
    {
        $token = Str::random(64);

        [$tenant, $user, $invitation] = DB::transaction(function () use ($type, $accountName, $contactName, $email, $token) {
            $tenant = Tenant::query()->create([
                'name' => trim($accountName),
                'slug' => $this->uniqueSlug($accountName),
                'tenant_type' => $type,
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
            // Süper admin işlemi TenantContext'e bağlı değil.
            $this->scopes->attach($user, 'tenant', $tenant->id);

            // Kurumsal hesapta müşteri menüsü yok: firmanın kendisi tek müşteri, organizasyonu bunun altında.
            if ($type === 'corporate') {
                Customer::query()->create(['tenant_id' => $tenant->id, 'name' => trim($accountName)]);
            }
            PkAccountUser::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'yonetici']);

            return [$tenant, $user, $this->invitation($tenant, $user, $token)];
        });

        $mailSent = true;
        try {
            $this->sendInvitation($invitation, $token);
        } catch (\Throwable) {
            $mailSent = false;
        }

        return [
            'tenant' => $tenant,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'mail_sent' => $mailSent,
        ];
    }

    // Davet yeniden: hesabın yöneticisine yeni bağlantı (gönderilemezse kayıt silinir).
    public function resendInvitation(Tenant $tenant): void
    {
        $user = $this->owner($tenant);
        abort_unless($user, 404, 'Bu hesabın giriş kullanıcısı bulunamadı.');

        $token = Str::random(64);
        $invitation = $this->invitation($tenant, $user, $token);

        try {
            $this->sendInvitation($invitation, $token);
        } catch (\Throwable $exception) {
            $invitation->delete();
            throw $exception;
        }
    }

    // Süper admin hesaba girer: yöneticinin adına oturum anahtarı; kullanıcı bilgisi girişteki biçimde.
    public function impersonate(Tenant $tenant): array
    {
        $user = $this->owner($tenant);
        abort_unless($user, 404, 'Bu hesabın giriş kullanıcısı bulunamadı.');

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

    // Oturumdaki kullanıcının hesabı ve pktakip rolü (panelde hesap türüne ve role göre yazılar, menü); hesabı yoksa
    // (süper admin) null.
    public function current(User $user): ?array
    {
        $tenant = $this->tenantOf($user);

        return $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'type' => $tenant->tenant_type, 'role' => $this->roleOf($tenant, $user)] : null;
    }

    public function tenantOf(User $user): ?Tenant
    {
        $tenantId = $this->userScopes->resolveTenantId($user);

        return $tenantId ? Tenant::query()->find($tenantId) : null;
    }

    // PKTakip rolü (yonetici | operasyon | uzman): OSGB / kurumsal kullanıcı kaydından; kaydı yoksa (bireysel uzman) Taşeron
    // rolü tenant ise yönetici.
    public function roleOf(Tenant $tenant, User $user): string
    {
        $row = PkAccountUser::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        return $row?->role ?? ($user->hasRole('tenant') ? 'yonetici' : 'uzman');
    }

    public function isPassive(Tenant $tenant, User $user): bool
    {
        return PkAccountUser::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereNotNull('deactivated_at')->exists();
    }

    // Hesabın yöneticisi: ilk açılan yönetici (rol tenant); yoksa hesaptaki ilk uzman kullanıcı.
    public function owner(Tenant $tenant): ?User
    {
        $users = fn () => User::query()
            ->where('is_expert', true)
            ->whereHas('scopes', fn ($query) => $query->where('scope_type', 'tenant')->where('scope_id', $tenant->id))
            ->orderBy('id');

        return $users()->whereHas('roles', fn ($query) => $query->where('name', 'tenant'))->first() ?? $users()->first();
    }

    // Davet kaydı (48 saat); şifre belirleme bağlantısı acceptUrl.
    public function invitation(Tenant $tenant, User $user, string $token): ExpertInvitation
    {
        return ExpertInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(48),
        ]);
    }

    private function sendInvitation(ExpertInvitation $invitation, string $token): void
    {
        Mail::to($invitation->email)->send(new PkAccountInvitationMail($invitation->load('tenant'), $this->acceptUrl($invitation, $token)));
    }

    // Şifre belirleme sayfası (uzman davetiyle aynı: experts/set-password).
    public function acceptUrl(ExpertInvitation $invitation, string $token): string
    {
        return rtrim((string) env('FRONTEND_URL', env('APP_URL')), '/')
            . '/set-password?token=' . urlencode($token)
            . '&email=' . urlencode($invitation->email);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'hesap';
        $slug = $base;
        $counter = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }
}
