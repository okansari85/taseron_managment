<?php

namespace App\Services;

use App\Models\PkEquipment;
use App\Models\PkFinding;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Panel bildirimleri (2026-11-15), pk:notifications-generate her gün çağırır. Kontrol tarihine 30 / 7 / 0 gün kala ve tarih
 * geçince: ekipmanda lokasyon + gün başına bir bildirim (aynı gün dolan tüpler tek bildirim), tesisatta tesisat başına; alıcı
 * lokasyonu gören kullanıcılar. Bulgu termini (pk:finding-reminders'ın seçtiği bulgular, 10 / 3 gün kala ve geçince): sorumluya.
 * Her aralık bir kez (user_id + dedupe_key tekil); gün kaçarsa sıradaki aralıkta gelir. Tenant bağlamını çağıran kurar.
 */
class PkNotificationService
{
    // Önyüzdeki kontrol ekranları (/expert/kontroller/{tur}).
    private const BUCKETS = ['gecti' => 'suresi-gecen', '0' => 'bugun', '7' => '7-gun', '30' => '8-30-gun'];

    public function __construct(
        private PkFindingService $findings,
        private PkAccountService $accounts,
        private PkInstallationService $installations
    ) {
    }

    // Hesabın bildirimlerini üretir; yeni eklenen bildirim sayısı döner.
    public function generate(Tenant $tenant): int
    {
        $today = CarbonImmutable::today();
        $viewers = $this->viewers($tenant);
        $now = now();
        $rows = [];
        $push = function (array $userIds, string $type, string $key, string $title, string $body, string $link, array $payload) use (&$rows, $tenant, $now) {
            foreach (array_unique($userIds) as $userId) {
                $rows[] = ['tenant_id' => $tenant->id, 'user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body, 'link' => $link,
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'dedupe_key' => $key, 'created_at' => $now, 'updated_at' => $now];
            }
        };

        // Ekipman: aktif, kontrol tarihi 30 gün içinde ya da geçmiş (Genel Bakış'taki kalan gün hesabıyla aynı).
        $equipment = PkEquipment::query()->where('tenant_id', $tenant->id)
            ->where(fn ($query) => $query->where('is_active', true)->orWhereNull('is_active'))
            ->whereNotNull('next_control_date')->where('next_control_date', '<=', $today->addDays(30)->toDateString())
            ->with(['type:id,name', 'location:id,name'])->orderBy('id')->get();
        foreach ($equipment->groupBy(fn (PkEquipment $item) => $item->location_id . '|' . $item->next_control_date->format('Y-m-d')) as $group) {
            $first = $group->first();
            $date = $first->next_control_date->format('Y-m-d');
            $days = (int) $today->diffInDays(CarbonImmutable::parse($date), false);
            $kind = $this->controlKind($days);
            $single = $group->count() === 1;
            $label = $single ? trim(($first->name ?: $first->type?->name) . ' ' . $first->code) : $group->count() . ' ekipman';
            [$title, $body] = $this->controlText($kind, (string) $first->location?->name, $label, $date, $days);
            $push($viewers((int) $first->location_id), $kind === 'gecti' ? 'kontrol_geciken' : 'kontrol_yaklasan', "kontrol:eq:{$first->location_id}:{$date}:{$kind}",
                $title, $body, $single ? "/expert/equipment/{$first->id}" : '/expert/kontroller/' . self::BUCKETS[$kind],
                ['kind' => $kind, 'date' => $date, 'location_id' => $first->location_id, 'equipment_ids' => $group->pluck('id')->values()->all()]);
        }

        // Tesisat: sonraki kontrol tarihi tesisat listesindeki gibi (son rapor; trafo merkezinde en erken dolan).
        foreach ($this->installations->list([]) as $item) {
            $days = $item['days_left'];
            $locationId = $item['location']['id'] ?? null;
            if ($days === null || $days > 30 || !$locationId) {
                continue;
            }
            $kind = $this->controlKind($days);
            [$title, $body] = $this->controlText($kind, (string) ($item['location']['name'] ?? ''), (string) $item['name'], $item['next_control_date'], $days);
            $push($viewers((int) $locationId), $kind === 'gecti' ? 'kontrol_geciken' : 'kontrol_yaklasan', "kontrol:ins:{$item['id']}:{$item['next_control_date']}:{$kind}",
                $title, $body, '/expert/installations',
                ['kind' => $kind, 'date' => $item['next_control_date'], 'location_id' => $locationId, 'installation_id' => $item['id']]);
        }

        // Bulgu termini: pk:finding-reminders ile aynı seçim ve aralıklar (o komutta çağrılabilir bir yardımcı yok).
        $findings = PkFinding::query()->where('tenant_id', $tenant->id)->where('status', '!=', 'kapandi')->whereNotNull('due_date')->whereNotNull('responsible_user_id')
            ->where('due_date', '<=', $today->addDays(10)->toDateString())->with('location:id,name')->orderBy('id')->get();
        foreach ($findings as $finding) {
            $days = (int) $today->diffInDays(CarbonImmutable::parse($finding->due_date), false);
            $kind = $days < 0 ? 'gecti' : ($days <= 3 ? '3' : '10');
            $due = $finding->due_date->format('Y-m-d');
            $title = match (true) {
                $days < 0 => 'Bulgunun termini geçti',
                $days === 0 => 'Bulgunun termini bugün',
                default => "Bulgunun terminine {$days} gün kaldı",
            };
            $place = $finding->location?->name ? $finding->location->name . ': ' : '';
            $push([(int) $finding->responsible_user_id], 'bulgu_hatirlatma', "bulgu:{$finding->id}:{$due}:{$kind}", $title,
                $place . Str::limit($finding->text, 150) . ' · termin ' . $finding->due_date->format('d.m.Y'),
                "/expert/bulgu-takip?bulgu={$finding->id}", ['kind' => $kind, 'due_date' => $due, 'finding_id' => $finding->id]);
        }

        // Tekil anahtar (user_id + dedupe_key) aynı bildirimi ikinci kez yazdırmaz.
        return collect($rows)->chunk(500)->sum(fn ($chunk) => DB::table('pk_notifications')->insertOrIgnore($chunk->values()->all()));
    }

    // Kontrol aralığı: geçti / bugün / 1–7 gün / 8–30 gün.
    private function controlKind(int $days): string
    {
        return match (true) {
            $days < 0 => 'gecti',
            $days === 0 => '0',
            $days <= 7 => '7',
            default => '30',
        };
    }

    /** @return array{0: string, 1: string} [başlık, metin] */
    private function controlText(string $kind, string $location, string $label, string $date, int $days): array
    {
        $day = CarbonImmutable::parse($date)->format('d.m.Y');
        $subject = trim($location . ' · ' . $label, ' ·');

        return match ($kind) {
            'gecti' => ['Kontrol süresi geçti', "{$subject}: kontrol tarihi {$day} idi."],
            '0' => ['Kontrol tarihi bugün', "{$subject}: kontrol tarihi bugün ({$day})."],
            default => ['Kontrol yaklaşıyor', "{$subject}: kontrol tarihine {$days} gün kaldı ({$day})."],
        };
    }

    /**
     * Lokasyonu gören kullanıcılar (önyüzdeki Firmalarım listesiyle aynı ölçüt), hesabın aktif kullanıcılarından: bireysel uzman
     * hesabında hepsi; OSGB / kurumsal hesapta yönetici ve operasyon yöneticisi her lokasyonu, uzman atandığı işyerlerinin
     * lokasyonlarını görür.
     */
    private function viewers(Tenant $tenant): Closure
    {
        $users = User::query()->whereKey($this->findings->responsibles()->pluck('id'))->get();
        if (!in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true)) {
            $all = $users->pluck('id')->all();

            return fn (int $locationId) => $all;
        }
        [$managers, $experts] = $users->partition(fn (User $user) => in_array($this->accounts->roleOf($tenant, $user), ['yonetici', 'operasyon'], true));
        $managerIds = $managers->pluck('id')->all();
        $assigned = DB::table('location_experts')
            ->join('location_business_entities', 'location_business_entities.id', '=', 'location_experts.location_business_entity_id')
            ->whereIn('location_experts.user_id', $experts->pluck('id'))
            ->get(['location_experts.user_id', 'location_business_entities.location_id'])
            ->groupBy('location_id')->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        return fn (int $locationId) => [...$managerIds, ...($assigned[$locationId] ?? [])];
    }
}
