<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PkAccountService;
use App\Services\PkBillingService;
use App\Services\SuperAdminExpertOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tanıtım sitesinden (pktakip_landing) herkese açık üyelik: bireysel uzman, OSGB ya da kurumsal hesap açılır ve
 * süper adminin hesap açarken kullandığı davet e-postası ("şifrenizi belirleyin") gönderilir. Ücretsiz deneme
 * (20 kredi + 20 ekipman) hesap ilk kullanıldığında PkBillingService tarafından açılır; burada ayrıca bir şey yapılmaz.
 * Kötüye kullanıma karşı: istek sınırı (route), görünmez tuzak alan ("website" dolu gelirse hesap açılmaz).
 */
class PkSignupController extends Controller
{
    public function __construct(
        private SuperAdminExpertOnboardingService $experts,
        private PkAccountService $accounts,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['expert', 'osgb', 'corporate'])],
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['required_unless:type,expert', 'nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'kvkk' => ['accepted'],
            'website' => ['nullable', 'string', 'max:255'],
        ], [
            'type.required' => 'Hesap türünü seçin.',
            'name.required' => 'Ad soyad zorunludur.',
            'organization.required_unless' => 'Kurum adı zorunludur.',
            'email.required' => 'E-posta zorunludur.',
            'email.email' => 'Geçerli bir e-posta adresi girin.',
            'email.unique' => 'Bu e-posta ile zaten bir hesap var. Giriş yapın ya da şifrenizi sıfırlayın.',
            'kvkk.accepted' => 'KVKK Aydınlatma Metni onayı gereklidir.',
        ]);

        // Tuzak alan doluysa (bot) hesap açmadan başarılı gibi yanıt ver.
        if (filled($data['website'] ?? null)) {
            return response()->json(['message' => 'Hesabınız oluşturuldu. E-postanızı kontrol edin.'], 201);
        }

        $name = trim($data['name']);
        $result = $data['type'] === 'expert'
            ? $this->experts->create($name, $name, $data['email'])
            : $this->accounts->create($data['type'], trim($data['organization']), $name, $data['email']);

        return response()->json([
            'message' => $result['mail_sent']
                ? 'Hesabınız oluşturuldu. Şifrenizi belirlemek için e-postanızı kontrol edin.'
                : 'Hesabınız oluşturuldu ancak e-posta gönderilemedi. Lütfen bizimle iletişime geçin.',
            'mail_sent' => $result['mail_sent'],
        ], 201);
    }

    /**
     * Ücretli paketle üyelik (Paketler sayfası). ŞİMDİLİK DENEME ÖDEMESİ: gerçek ödeme alınmaz; ödeme altyapısı
     * (muhasebeden bilgiler gelince) bağlanana kadar yalnız PK_FAKE_PAYMENT=true ortamında çalışır, aksi halde 403.
     * Hesap açılır, seçilen paket atanır (deneme kredisi sıfırlanıp paketin ilk ayı yüklenir), davet e-postası gider ve
     * kullanıcının doğrudan açılacağı şifre belirleme bağlantısı döner (ayrı bir davet kaydı, 48 saat geçerli).
     */
    public function checkout(Request $request, PkBillingService $billing): JsonResponse
    {
        abort_unless(filter_var(env('PK_FAKE_PAYMENT', false), FILTER_VALIDATE_BOOL), 403, 'Online ödeme henüz aktif değil. Lütfen bizimle iletişime geçin.');

        $data = $request->validate([
            'type' => ['required', Rule::in(['expert', 'osgb', 'corporate'])],
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['required_unless:type,expert', 'nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'plan' => ['required', 'string', 'max:100'],
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
            'kvkk' => ['accepted'],
        ], [
            'name.required' => 'Ad soyad zorunludur.',
            'organization.required_unless' => 'Kurum adı zorunludur.',
            'email.required' => 'E-posta zorunludur.',
            'email.email' => 'Geçerli bir e-posta adresi girin.',
            'email.unique' => 'Bu e-posta ile zaten bir hesap var. Giriş yapın ya da şifrenizi sıfırlayın.',
            'kvkk.accepted' => 'KVKK Aydınlatma Metni onayı gereklidir.',
        ]);

        // Paket, hesap açılmadan önce doğrulanır (pk_packages.account_type: expert / osgb / corporate).
        $package = DB::table('pk_packages')->where('name', $data['plan'])->where('account_type', $data['type'])->where('is_active', true)->first();
        if (!$package) {
            throw ValidationException::withMessages(['plan' => 'Seçilen paket bulunamadı.']);
        }

        $name = trim($data['name']);
        $result = $data['type'] === 'expert'
            ? $this->experts->create($name, $name, $data['email'])
            : $this->accounts->create($data['type'], trim($data['organization']), $name, $data['email']);

        $tenant = $result['tenant'];
        $period = $data['period'] === 'yearly' ? 'yıllık' : 'aylık';
        $billing->assign($tenant->id, 'package', $package->id, null, "Tanıtım sitesinden {$period} (deneme ödemesi)");

        // Kullanıcı ödeme sonrası doğrudan şifre belirleme ekranına gider; e-postadaki bağlantı da geçerli kalır.
        $token = Str::random(64);
        $user = User::query()->findOrFail($result['user']['id']);
        $this->accounts->invitation($tenant, $user, $token);
        $setPasswordUrl = rtrim((string) env('FRONTEND_URL', env('APP_URL')), '/')
            . '/set-password?token=' . urlencode($token) . '&email=' . urlencode($user->email);

        return response()->json([
            'message' => "Ödeme alındı. {$package->name} paketiniz tanımlandı.",
            'set_password_url' => $setPasswordUrl,
            'mail_sent' => $result['mail_sent'],
        ], 201);
    }
}
