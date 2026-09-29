<?php

namespace App\Services;

use App\Models\Location;
use App\Models\PeriodicEquipmentType;
use App\Models\PkBulkReport;
use App\Models\PkInstallation;
use App\Services\Ai\PkTakip\PkBulkReportReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Genel Bakış ve Durum Raporu: uzmanın lokasyonlarındaki (ya da seçilen müşteri / lokasyon / işyerindeki) ekipman ve
 * tesisatların özeti. Aktif ekipman sayılır (pasifler ayrıca); yaklaşan kontrol 60 gün (0–30 ve 31–60), gecikmiş: süresi
 * geçmiş. Lokasyonlar sorunluluğa göre sıralanır (gecikmiş, uygun değil, yaklaşan).
 */
class PkDashboardService
{
    public const STATUSES = ['uygun', 'uygun_degil', 'belirtilmemis', 'rapor_bekleniyor', 'rapor_yok'];

    // Yangın ekipmanları (tüp, dolap, hidrant…): genel durumda ayrı kutu.
    public const FIRE_CATEGORY = 'yangin-ekipmanlari';

    // Kontrol takviminde bir günün grupları bu sırayla: tesisat, tüp, türler.
    private const GROUP_ORDER = ['installation' => 0, 'tube' => 1, 'type' => 2];

    // Yangın tüpü türü (takvimde tek kalem); bir kez okunur.
    private ?int $tubeType = null;

    public function __construct(
        private PkEquipmentService $equipment,
        private PkInstallationService $installations
    ) {
    }

    public function overview(array $locationIds, ?int $workplaceId): array
    {
        $all = $this->equipmentOf($locationIds, $workplaceId);
        $active = $all->where('is_active', true)->values();
        $locations = Location::query()->whereIn('id', $locationIds)->pluck('name', 'id');

        return [
            'totals' => [
                'equipment' => $active->count(),
                'passive' => $all->count() - $active->count(),
                'overdue' => $active->filter(fn ($item) => $this->bucket($item) === 'overdue')->count(),
                'upcoming' => $active->filter(fn ($item) => in_array($this->bucket($item), ['d30', 'd60'], true))->count(),
                'nonconforming' => $active->where('status', 'uygun_degil')->count(),
                'locations' => count($locationIds),
            ],
            'status' => $this->statusCounts($active),
            'score' => $this->scoreOf($active),
            // Kategori bazında PK durumu (genel skorla aynı hesap; yangın ekipmanları dahil), ekipman sayısına göre.
            'category_scores' => $active->groupBy(fn ($item) => $item['category']['slug'] ?? 'diger')
                ->map(fn (Collection $items, string $slug) => ['slug' => $slug, 'name' => $items->first()['category']['name'] ?? 'Diğer', 'score' => $this->scoreOf($items)])
                ->sortByDesc(fn ($row) => $row['score']['total'])->values(),
            'timing' => collect(['overdue', 'd30', 'd60', 'later', 'none'])->mapWithKeys(fn ($key) => [$key => $active->filter(fn ($item) => $this->bucket($item) === $key)->count()]),
            // Kontrol takvimi: kontrol günleri (gün + lokasyon), gruplu; tarihi olmayan ekipman takvimde yok (undated).
            'agenda' => $this->agenda($active),
            'undated' => $active->whereNull('next_control_date')->count(),
            // Genel durum: ana kategoriler (yangın ekipmanları hariç) ve yangın ekipmanları ayrı, türe göre (sayıca çok);
            // satıra tıklanınca açılan ayrıntıyla (kategoride türler, yangın türünde lokasyonlar; uygun olmayanlar).
            'categories' => $this->categories($other = $active->reject(fn ($item) => $this->isFire($item)))
                ->map(fn ($row) => $row + $this->detail($other->filter(fn ($item) => ($item['category']['slug'] ?? 'diger') === $row['slug']), 'type'))->values(),
            'fire_types' => $this->types($fire = $active->filter(fn ($item) => $this->isFire($item)))
                ->map(fn ($row) => $row + $this->detail($fire->filter(fn ($item) => ($item['type']['id'] ?? null) === $row['type_id']), 'location'))->values(),
            'locations' => $this->locations($active, $locations),
            'installations' => $this->installations->list(['location_ids' => $locationIds, 'workplace_id' => $workplaceId])
                ->map(fn (array $item) => $item + ['location_name' => $item['location']['name'] ?? null])->values(),
            'bulk_reports' => $this->latestBulkReports($locationIds, $workplaceId, $locations),
        ];
    }

    /**
     * Durum Raporu: kategori / tür tablosu, sorunlu ekipmanlar (gecikmiş ya da uygun değil) ve tesisatlar sistemleriyle
     * (durum, bulgu, sistemdeki ekipmanların durumu).
     */
    public function report(array $locationIds, ?int $workplaceId): array
    {
        $all = $this->equipmentOf($locationIds, $workplaceId);
        $active = $all->where('is_active', true)->values();
        $locations = Location::query()->whereIn('id', $locationIds)->pluck('name', 'id');

        $categories = $this->categories($active);

        $installations = PkInstallation::query()->whereIn('location_id', $locationIds)
            ->when($workplaceId, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('location_business_entity_id')->orWhere('location_business_entity_id', $workplaceId)))
            ->orderBy('location_id')->orderBy('id')->get()
            ->map(function (PkInstallation $installation) {
                $detail = $this->installations->show($installation);

                return array_intersect_key($detail, array_flip(['id', 'name', 'type', 'location', 'workplace', 'status', 'last_control_date', 'next_control_date', 'days_left', 'inspection_body', 'report_no', 'general_findings'])) + [
                    'systems' => collect($detail['systems'])->map(fn ($system) => [
                        'name' => $system['name'],
                        'card' => $system['card'],
                        'status' => $system['status'],
                        'findings' => $system['findings'],
                        'equipment_total' => count($system['equipment'] ?? []),
                        'equipment_status' => $this->statusCounts(collect($system['equipment'] ?? [])),
                    ])->values(),
                ];
            })->values();

        return [
            'totals' => [
                'equipment' => $active->count(),
                'passive' => $all->count() - $active->count(),
                'overdue' => $active->filter(fn ($item) => $this->bucket($item) === 'overdue')->count(),
                'upcoming' => $active->filter(fn ($item) => in_array($this->bucket($item), ['d30', 'd60'], true))->count(),
                'nonconforming' => $active->where('status', 'uygun_degil')->count(),
            ],
            'status' => $this->statusCounts($active),
            'categories' => $categories,
            'types' => $this->types($active),
            'locations' => $this->locations($active, $locations),
            // Sorunlu ekipman: gecikmiş ya da son kontrolü uygun değil (önce gecikmiş, en eski).
            'problems' => $active->filter(fn ($item) => $this->bucket($item) === 'overdue' || $item['status'] === 'uygun_degil')
                ->sortBy(fn ($item) => [$item['days_left'] ?? 99999])
                ->map(fn ($item) => $this->row($item))->values(),
            'installations' => $installations,
        ];
    }

    // Kontrol takviminde bir günün ("2026-11-05") bir grubu (agenda grup anahtarı: tesisat, tüp ya da tür): o gün kontrolü
    // dolan aktif ekipmanlar.
    public function due(array $locationIds, ?int $workplaceId, string $date, string $group): Collection
    {
        return $this->equipmentOf($locationIds, $workplaceId)
            ->where('is_active', true)
            ->filter(fn ($item) => $item['next_control_date'] === $date && $this->agendaGroup($item)['key'] === $group)
            ->sortBy(fn ($item) => [$item['type']['name'] ?? '', $item['place'] ?? '', $item['code'] ?? ''])
            ->map(fn ($item) => $this->row($item))
            ->values();
    }

    private function equipmentOf(array $locationIds, ?int $workplaceId): Collection
    {
        return $locationIds ? $this->equipment->list(['location_ids' => $locationIds, 'workplace_id' => $workplaceId]) : collect();
    }

    // Kontrol zamanı: gecikmiş / 30 gün içinde / 31–60 gün / 60 günden sonra / tarihi yok.
    private function bucket(array $item): string
    {
        $days = $item['days_left'];

        return match (true) {
            $days === null => 'none',
            $days < 0 => 'overdue',
            $days <= 30 => 'd30',
            $days <= 60 => 'd60',
            default => 'later',
        };
    }

    private function statusCounts(Collection $items): array
    {
        return collect(self::STATUSES)->mapWithKeys(fn ($status) => [$status => $items->where('status', $status)->count()])->all();
    }

    /**
     * Kontrol takvimi: kontrol günleri (gün + lokasyon), o gün kontrolü dolan ekipman gruplu ("3 Forklift, 2 Transpalet").
     * Tesisata bağlı ekipman tek tek değil tesisat olarak, tüpler tek kalem; tarih sırasıyla (en eski önce).
     */
    private function agenda(Collection $active): Collection
    {
        return $active->filter(fn ($item) => $item['next_control_date'])
            ->groupBy(fn ($item) => $item['next_control_date'] . '|' . ($item['location']['id'] ?? 0))
            ->map(function (Collection $items) {
                $first = $items->first();

                return [
                    'date' => $first['next_control_date'],
                    'days_left' => $first['days_left'],
                    'location_id' => $first['location']['id'] ?? null,
                    'location_name' => $first['location']['name'] ?? null,
                    'total' => $items->count(),
                    'groups' => $items->groupBy(fn ($item) => $this->agendaGroup($item)['key'])
                        ->map(fn (Collection $group) => $this->agendaGroup($group->first()) + ['count' => $group->count()])
                        ->sortBy(fn ($group) => [self::GROUP_ORDER[$group['kind']], -$group['count'], $group['name']])
                        ->values(),
                ];
            })
            ->sortBy(fn ($day) => [$day['date'], $day['location_name'] ?? ''])
            ->values();
    }

    // Takvim grubu: tesisata bağlıysa tesisatı, tüpse "Yangın tüpü", değilse türü. key: gün listesini açarken (due).
    private function agendaGroup(array $item): array
    {
        if (!empty($item['installation'])) {
            return ['key' => 'installation-' . $item['installation']['id'], 'kind' => 'installation', 'id' => $item['installation']['id'], 'name' => $item['installation']['name']];
        }
        $typeId = $item['type']['id'] ?? null;
        if ($typeId !== null && $typeId === $this->tubeTypeId()) {
            return ['key' => 'tube', 'kind' => 'tube', 'id' => $typeId, 'name' => 'Yangın tüpü'];
        }

        return ['key' => 'type-' . ($typeId ?? 0), 'kind' => 'type', 'id' => $typeId, 'name' => $item['type']['name'] ?? 'Tür yok'];
    }

    private function tubeTypeId(): int
    {
        return $this->tubeType ??= (int) PeriodicEquipmentType::query()->where('slug', PkBulkReportReader::TYPE_SLUG)->value('id');
    }

    private function isFire(array $item): bool
    {
        return ($item['category']['slug'] ?? null) === self::FIRE_CATEGORY;
    }

    /**
     * Genel skor: ekipmanların yüzde kaçı tamam. Tamam: kontrol süresi geçmemiş ve son kontrolü uygun ya da belirtilmemiş
     * (raporda var, bulgusu yok). Tamam olmayan her ekipman tek nedene sayılır, öncelik sırasıyla: süresi geçmiş, uygun
     * değil, rapor bekleniyor, rapor yok.
     */
    private function scoreOf(Collection $active): array
    {
        $reason = fn ($item) => match (true) {
            $this->bucket($item) === 'overdue' => 'overdue',
            $item['status'] === 'uygun_degil' => 'nonconforming',
            $item['status'] === 'rapor_bekleniyor' => 'pending',
            $item['status'] === 'rapor_yok' => 'no_report',
            default => 'ok',
        };
        $counts = $active->countBy($reason);
        $total = $active->count();

        return [
            'total' => $total,
            'ok' => $counts['ok'] ?? 0,
            // Tek haneli, aşağı yuvarlanmış (tamam olmayan varken %100 görünmesin).
            'percent' => $total ? floor((($counts['ok'] ?? 0) / $total) * 1000) / 10 : null,
            'overdue' => $counts['overdue'] ?? 0,
            'nonconforming' => $counts['nonconforming'] ?? 0,
            'pending' => $counts['pending'] ?? 0,
            'no_report' => $counts['no_report'] ?? 0,
        ];
    }

    /**
     * Genel durum ayrıntısı (satıra tıklanınca açılır): alt kırılım ($by type: türler, location: lokasyonlar) durumlarıyla
     * ve son kontrolü uygun olmayan ekipmanlar (ilk 10 + toplam).
     */
    private function detail(Collection $items, string $by): array
    {
        $groups = $items->groupBy(fn ($item) => $by === 'type' ? ($item['type']['name'] ?? 'Tür yok') : ($item['location']['id'] ?? 0));
        $nonconforming = $items->where('status', 'uygun_degil')->values();

        return [
            'breakdown' => $groups->map(fn (Collection $group) => [
                'name' => $by === 'type' ? ($group->first()['type']['name'] ?? 'Tür yok') : ($group->first()['location']['name'] ?? '—'),
                'location_id' => $by === 'location' ? ($group->first()['location']['id'] ?? null) : null,
                'total' => $group->count(),
                'status' => $this->statusCounts($group),
            ])->sortByDesc('total')->values(),
            'nonconforming' => $nonconforming->take(10)->map(fn ($item) => $this->row($item))->values(),
            'nonconforming_total' => $nonconforming->count(),
        ];
    }

    // Kategori başına durum ve kontrol zamanı (ekipman sayısına göre).
    private function categories(Collection $active): Collection
    {
        return $active->groupBy(fn ($item) => $item['category']['slug'] ?? 'diger')->map(fn (Collection $items, string $slug) => [
            'slug' => $slug,
            'name' => $items->first()['category']['name'] ?? 'Diğer',
            'total' => $items->count(),
            'status' => $this->statusCounts($items),
            'overdue' => $items->filter(fn ($item) => $this->bucket($item) === 'overdue')->count(),
            'upcoming' => $items->filter(fn ($item) => in_array($this->bucket($item), ['d30', 'd60'], true))->count(),
        ])->sortByDesc('total')->values();
    }

    private function types(Collection $active): Collection
    {
        return $active->groupBy(fn ($item) => $item['type']['id'] ?? 0)->map(fn (Collection $items) => [
            'type_id' => $items->first()['type']['id'] ?? null,
            'name' => $items->first()['type']['name'] ?? 'Tür yok',
            'category' => $items->first()['category']['name'] ?? null,
            'total' => $items->count(),
            'status' => $this->statusCounts($items),
            'overdue' => $items->filter(fn ($item) => $this->bucket($item) === 'overdue')->count(),
            'upcoming' => $items->filter(fn ($item) => in_array($this->bucket($item), ['d30', 'd60'], true))->count(),
        ])->sortByDesc('total')->values();
    }

    // Lokasyonlar sorunluluğa göre: gecikmiş, uygun değil, yaklaşan (ekipmanı olmayan lokasyon da listelenir).
    private function locations(Collection $active, Collection $names): Collection
    {
        // İl (Türkiye haritası için; il kimliği plaka kodu).
        $cities = Location::query()->with('city:id,name')->whereIn('id', $names->keys())->get(['id', 'city_id'])->keyBy('id');

        return $names->map(function (string $name, int $id) use ($active, $cities) {
            $items = $active->where('location.id', $id);

            return [
                'location_id' => $id,
                'location_name' => $name,
                'city_id' => $cities[$id]?->city_id,
                'city_name' => $cities[$id]?->city?->name,
                'total' => $items->count(),
                'overdue' => $items->filter(fn ($item) => $this->bucket($item) === 'overdue')->count(),
                'upcoming' => $items->filter(fn ($item) => in_array($this->bucket($item), ['d30', 'd60'], true))->count(),
                'nonconforming' => $items->where('status', 'uygun_degil')->count(),
                'no_report' => $items->where('status', 'rapor_yok')->count(),
                'status' => $this->statusCounts($items),
                // Lokasyonun PK durumu: genel skorla aynı hesap (yüzde kaçı tamam).
                'score' => $this->scoreOf($items),
            ];
        })->sortBy(fn ($item) => [-$item['overdue'], -$item['nonconforming'], -$item['upcoming'], $item['location_name']])->values();
    }

    // Lokasyon başına son tüp kontrol formu.
    private function latestBulkReports(array $locationIds, ?int $workplaceId, Collection $names): Collection
    {
        return PkBulkReport::query()->whereIn('location_id', $locationIds)
            ->when($workplaceId, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('location_business_entity_id')->orWhere('location_business_entity_id', $workplaceId)))
            ->withCount('inspections')
            ->orderByDesc('control_date')->orderByDesc('id')
            ->get()
            ->unique('location_id')
            ->map(fn (PkBulkReport $report) => [
                'id' => $report->id,
                'location_id' => $report->location_id,
                'location_name' => $names[$report->location_id] ?? null,
                'control_date' => $report->control_date?->format('Y-m-d'),
                'next_control_date' => $report->next_control_date?->format('Y-m-d'),
                'days_left' => $report->next_control_date ? (int) CarbonImmutable::today()->diffInDays($report->next_control_date, false) : null,
                'status' => $report->status,
                'report_no' => $report->report_no,
                'equipment_count' => $report->inspections_count,
            ])->values();
    }

    private function row(array $item): array
    {
        return [
            'id' => $item['id'],
            'name' => $item['name'] ?: ($item['type']['name'] ?? null),
            'type' => $item['type']['name'] ?? null,
            'variant' => $item['variant'],
            'code' => $item['code'],
            'place' => $item['place'],
            'location_id' => $item['location']['id'] ?? null,
            'location_name' => $item['location']['name'] ?? null,
            'workplace' => $item['workplace']['company_name'] ?? null,
            'status' => $item['status'],
            'next_control_date' => $item['next_control_date'],
            'days_left' => $item['days_left'],
        ];
    }
}
