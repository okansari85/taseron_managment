<?php

namespace App\Services;

use App\Models\LocationEmergencyEquipment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class FireSafetyDashboardService
{
    private const UPCOMING_WINDOW_DAYS = 7;

    public function build(): array
    {
        $equipment = LocationEmergencyEquipment::query()
            ->where('is_active', true)
            ->with([
                'equipmentType:id,name,inspection_frequency_days',
                'latestInspection:id,location_emergency_equipment_id,overall_result,inspected_at',
                'locationBusinessEntity:id,location_id,business_entity_id',
                'locationBusinessEntity.businessEntity:id,name',
                'locationBusinessEntity.brands:id,name',
            ])
            ->get();

        $now = Carbon::now();
        $horizon = $now->copy()->addDays(self::UPCOMING_WINDOW_DAYS);

        $branchStats = [];
        $brandCounts = [];
        $categoryOpen = [];
        $overdueList = [];
        $upcomingList = [];

        $totalEquipment = 0;
        $upToDate = 0;
        $upcomingCount = 0;
        $overdueCount = 0;
        $openNonconformities = 0;

        foreach ($equipment as $item) {
            $totalEquipment++;

            $type = $item->equipmentType;
            $frequency = $type?->inspection_frequency_days;
            $latest = $item->latestInspection;
            $branchEntity = $item->locationBusinessEntity;
            $branchName = $branchEntity?->businessEntity?->name ?? 'Bilinmeyen Şube';
            $brandName = $branchEntity?->brands?->first()?->name ?? 'Markasız';
            $branchKey = $branchEntity?->id ?? 0;

            if (! isset($branchStats[$branchKey])) {
                $branchStats[$branchKey] = [
                    'location_business_entity_id' => $branchKey ?: null,
                    'branch_name' => $branchName,
                    'brand_name' => $brandName,
                    'equipment_count' => 0,
                    'overdue_count' => 0,
                    'open_count' => 0,
                ];
            }
            $branchStats[$branchKey]['equipment_count']++;

            $brandCounts[$brandName] = ($brandCounts[$brandName] ?? 0) + 1;

            $isOpenNonconformity = $latest !== null && $latest->overall_result === 'failed';

            if ($isOpenNonconformity) {
                $openNonconformities++;
                $branchStats[$branchKey]['open_count']++;
                $typeName = $type?->name ?? 'Diğer';
                $categoryOpen[$typeName] = ($categoryOpen[$typeName] ?? 0) + 1;
            }

            $dueDate = $this->resolveDueDate($item, $latest, $frequency);

            if ($dueDate === null) {
                if ($latest !== null) {
                    $upToDate++;
                }
            } elseif ($dueDate->lt($now)) {
                $overdueCount++;
                $branchStats[$branchKey]['overdue_count']++;
                $overdueList[] = [
                    'equipment_id' => $item->id,
                    'branch_name' => $branchName,
                    'equipment_type_name' => $type?->name ?? 'Diğer',
                    'code' => $item->code,
                    'due_date' => $dueDate->toDateString(),
                    'days_overdue' => (int) $now->diffInDays($dueDate),
                ];
            } elseif ($dueDate->lte($horizon)) {
                $upcomingCount++;
                $upcomingList[] = [
                    'equipment_id' => $item->id,
                    'branch_name' => $branchName,
                    'equipment_type_name' => $type?->name ?? 'Diğer',
                    'code' => $item->code,
                    'due_date' => $dueDate->toDateString(),
                    'days_until' => (int) $now->diffInDays($dueDate),
                ];
            } else {
                $upToDate++;
            }
        }

        usort($overdueList, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);
        usort($upcomingList, fn ($a, $b) => $a['days_until'] <=> $b['days_until']);

        $branchStatusList = collect($branchStats)
            ->map(function (array $branch): array {
                $branch['status'] = $branch['open_count'] > 0
                    ? 'critical'
                    : ($branch['overdue_count'] > 0 ? 'warning' : 'good');

                return $branch;
            })
            ->sortBy(fn (array $branch) => match ($branch['status']) {
                'critical' => 0,
                'warning' => 1,
                default => 2,
            })
            ->values()
            ->all();

        $brandDistribution = collect($brandCounts)
            ->map(fn (int $count, string $name) => ['brand_name' => $name, 'equipment_count' => $count])
            ->values()
            ->all();

        $categoryNonconformities = collect($categoryOpen)
            ->map(fn (int $count, string $name) => ['equipment_type_name' => $name, 'open_count' => $count])
            ->sortByDesc('open_count')
            ->values()
            ->all();

        return [
            'stats' => [
                'total_branches' => count($branchStats),
                'total_equipment' => $totalEquipment,
                'up_to_date' => $upToDate,
                'upcoming' => $upcomingCount,
                'overdue' => $overdueCount,
                'open_nonconformities' => $openNonconformities,
            ],
            'brand_distribution' => $brandDistribution,
            'category_nonconformities' => $categoryNonconformities,
            'branch_status' => $branchStatusList,
            'overdue_list' => array_slice($overdueList, 0, 10),
            'upcoming_list' => array_slice($upcomingList, 0, 10),
        ];
    }

    private function resolveDueDate(LocationEmergencyEquipment $item, $latest, ?int $frequency): ?CarbonInterface
    {
        if (! $frequency) {
            return null;
        }

        $baseDate = $latest?->inspected_at ?? $item->install_date ?? $item->created_at;

        if ($baseDate === null) {
            return null;
        }

        return Carbon::parse($baseDate)->addDays($frequency);
    }
}
