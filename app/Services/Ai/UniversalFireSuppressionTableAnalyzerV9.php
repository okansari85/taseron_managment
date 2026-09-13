<?php
namespace App\Services\Ai;

/**
 * V9: Kullanıcı çıktısını sadeleştirir.
 *
 * Matrix üretmez. Ekipman ve ekipmana ait gerçek kontrol sonuçlarını primary
 * veri olarak bırakır. Sistem özeti; ekipmanlardan güvenilir şekilde
 * hesaplanabiliyorsa ekipman/kontrol/uygunsuzluk sayılarını üretir.
 */
class UniversalFireSuppressionTableAnalyzerV9 extends UniversalFireSuppressionTableAnalyzerV8
{
    public function analyze(array $pages, array $semantic): array
    {
        $result = parent::analyze($pages, $semantic);

        $controlMap = [];
        foreach ((array)($result['control_matrix'] ?? []) as $control) {
            $code = trim((string)($control['control_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $controlMap[$code] = [
                'code' => $code,
                'description' => $control['description'] ?? null,
            ];
        }

        $equipment = [];
        foreach ((array)($result['equipment'] ?? []) as $item) {
            $controls = [];
            foreach ((array)($item['control_items'] ?? []) as $code => $status) {
                $code = trim((string)$code);
                if ($code === '') {
                    continue;
                }
                $status = strtoupper(trim((string)$status));
                if (!in_array($status, ['U', 'UD', 'N'], true)) {
                    continue;
                }
                $controls[] = [
                    'code' => $code,
                    'status' => $status,
                    'description' => $controlMap[$code]['description'] ?? null,
                ];
            }

            $item['control_items'] = $controls;
            $item['status'] = $this->equipmentStatus($controls);
            $item['nonconforming_controls'] = array_values(array_filter(
                $controls,
                fn(array $control) => ($control['status'] ?? null) === 'UD'
            ));
            $equipment[] = $item;
        }
        $result['equipment'] = $equipment;

        $result['systems'] = array_map(function (array $system) use ($equipment): array {
            $category = (string)($system['category'] ?? '');
            $name = (string)($system['name'] ?? '');

            $components = array_values(array_filter(
                $equipment,
                fn(array $item) => (string)($item['category'] ?? '') === $category
                    && ($name === '' || (string)($item['system_name'] ?? '') === $name)
            ));

            $controlByCode = [];
            $nonconformingEquipment = 0;
            foreach ($components as $item) {
                $hasUd = false;
                foreach ((array)($item['control_items'] ?? []) as $control) {
                    $code = (string)($control['code'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    $controlByCode[$code] = $control;
                    if (($control['status'] ?? null) === 'UD') {
                        $hasUd = true;
                    }
                }
                if ($hasUd) {
                    $nonconformingEquipment++;
                }
            }

            $system['components'] = array_map(fn(array $item) => [
                'code' => $item['code'] ?? null,
                'name' => $item['name'] ?? null,
                'location' => $item['location_note'] ?? null,
                'brand' => $item['brand'] ?? null,
                'model' => $item['model'] ?? null,
                'serial_no' => $item['serial_no'] ?? null,
            ], $components);

            $system['equipment_count'] = count($components);
            $system['equipment_count_known'] = count($components) > 0;
            $system['control_count'] = count($controlByCode);
            $system['nonconforming_count'] = count(array_filter(
                $controlByCode,
                fn(array $control) => ($control['status'] ?? null) === 'UD'
            ));
            $system['nonconforming_equipment_count'] = $nonconformingEquipment;
            $system['status'] = $this->systemStatus($system, $components);

            // UI için gereksiz tablo/matrix çıktısını kaldır.
            unset($system['equipment_matrix'], $system['tables'], $system['control_items']);
            return $system;
        }, (array)($result['systems'] ?? []));

        $result['systems'] = array_values($result['systems']);

        // Kullanıcı çıktısında matrix yok. Kaynak tablolar da bu aşamada
        // frontend'e taşınmaz; teknik veri equipment[] içindedir.
        unset($result['control_matrix'], $result['tables']);

        $result['analyzer']['version'] = '9.0.0';
        $result['analyzer']['equipment_count'] = count($equipment);
        $result['analyzer']['control_count'] = $controlMap ? count($controlMap) : 0;

        return $result;
    }

    private function equipmentStatus(array $controls): string
    {
        foreach ($controls as $control) {
            if (($control['status'] ?? null) === 'UD') {
                return 'uygun_degil';
            }
        }
        if (!$controls) {
            return 'belirtilmemis';
        }
        return 'uygun';
    }

    private function systemStatus(array $system, array $components): string
    {
        if (($system['nonconforming_equipment_count'] ?? 0) > 0) {
            return 'uygunsuz';
        }
        if (($system['control_count'] ?? 0) > 0) {
            return 'uygun';
        }
        return 'belirtilmemis';
    }
}
