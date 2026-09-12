<?php

namespace App\Services\Ai;

/**
 * Firma ve rapor şablonundan bağımsız yangın tesisatı tablo analizörü.
 * Gemini yalnızca sistem/bulgu semantiğini verir; bu sınıf PDF metninden
 * tablo, devam sayfası, ekipman, teknik değer ve U/UD/N ilişkilerini çıkarır.
 */
class UniversalFireSuppressionTableAnalyzer
{
    public function analyze(array $pages, array $semantic): array
    {
        $tables = $this->mergeContinuationTables($this->discoverTables($pages));
        $semanticSystems = is_array($semantic['systems'] ?? null) ? $semantic['systems'] : [];
        $systems = [];
        $equipment = [];
        $controls = [];
        $used = [];

        foreach ($semanticSystems as $system) {
            if (!is_array($system)) continue;
            $name = trim((string) ($system['name'] ?? ''));
            $category = $this->category((string) ($system['category'] ?? ''), $name);
            $matched = $this->matchTables($tables, $name, $category);
            $used = array_merge($used, array_column($matched, 'id'));
            $matrix = ['codes' => [], 'locations' => []];
            $components = [];
            $systemControls = [];
            $systemTables = [];

            foreach ($matched as $table) {
                $systemTables[] = $this->tableForUi($table);
                $systemControls = array_merge($systemControls, $this->extractControls($table));
                if ($category === 'yangin_dolabi') {
                    $matrix = $this->mergeMatrix($matrix, $this->extractEquipmentMatrix($table));
                } else {
                    $components = array_merge($components, $this->extractComponents($table));
                }
            }

            if ($category === 'yangin_dolabi') {
                foreach ($matrix['codes'] as $i => $code) {
                    $components[] = [
                        'code' => $code,
                        'name' => 'Yangın Dolabı',
                        'location' => $matrix['locations'][$i] ?? null,
                        'brand' => null,
                        'model' => null,
                        'serial_no' => null,
                        'properties' => [],
                        'source_pages' => $this->pagesOfTables($matched),
                    ];
                }
            }

            $components = $this->uniqueComponents($components);
            $systemControls = $this->uniqueControls($systemControls);
            foreach ($components as $component) {
                $equipment[] = [
                    'code' => $component['code'] ?? $component['name'] ?? null,
                    'category' => $category,
                    'system_name' => $name !== '' ? $name : null,
                    'system_category' => $category,
                    'location_note' => $component['location'] ?? null,
                    'brand' => $component['brand'] ?? null,
                    'model' => $component['model'] ?? null,
                    'serial_no' => $component['serial_no'] ?? null,
                    'result' => null,
                    'note' => null,
                    'control_items' => [],
                    'properties' => $component['properties'] ?? [],
                    'source_pages' => $component['source_pages'] ?? [],
                ];
            }
            foreach ($systemControls as $control) {
                $control['system_name'] = $name !== '' ? $name : null;
                $control['category'] = $category;
                $controls[] = $control;
            }

            $systems[] = [
                'name' => $name !== '' ? $name : null,
                'category' => $category,
                'control_count' => count($systemControls),
                'nonconforming_count' => count(array_filter($systemControls, fn ($c) => ($c['nonconforming_count'] ?? 0) > 0)),
                'components' => $components,
                'equipment_matrix' => $matrix,
                'tables' => $systemTables,
                'control_items' => $systemControls,
            ];
        }

        $orphanTables = [];
        foreach ($tables as $table) {
            if (!in_array($table['id'], $used, true) && $this->isRelevantFireTable($table)) {
                $orphanTables[] = $this->tableForUi($table);
            }
        }

        return [
            'systems' => $this->uniqueSystems($systems),
            'equipment' => $this->uniqueComponents($equipment),
            'control_matrix' => $this->uniqueControls($controls),
            'tables' => $orphanTables,
            'analyzer' => [
                'version' => '1.1.0',
                'table_count' => count($tables),
                'equipment_count' => count($equipment),
                'control_count' => count($controls),
            ],
        ];
    }

    private function discoverTables(array $pages): array
    {
        $tables = [];
        $id = 1;
        foreach (array_values($pages) as $pageNo => $page) {
            $current = [];
            $start = 0;
            $lines = preg_split('/\R/u', (string) $page) ?: [];
            foreach ($lines as $lineNo => $raw) {
                $line = trim((string) $raw);
                if ($line === '') {
                    if (count($current) >= 2) $tables[] = $this->makeTable($id++, $pageNo + 1, $start, $current);
                    $current = [];
                    continue;
                }
                $cells = $this->splitRow($line);
                $isTable = count($cells) >= 2 || $this->isAttributeRow($line) || $this->isControlRow($line);
                if ($isTable) {
                    if ($current === []) $start = $lineNo;
                    $current[] = ['line' => $line, 'cells' => $cells, 'page' => $pageNo + 1];
                } elseif (count($current) >= 2) {
                    $tables[] = $this->makeTable($id++, $pageNo + 1, $start, $current);
                    $current = [];
                }
            }
            if (count($current) >= 2) $tables[] = $this->makeTable($id++, $pageNo + 1, $start, $current);
        }
        return $tables;
    }

    private function makeTable(int $id, int $page, int $start, array $rows): array
    {
        $max = max(array_map(fn ($r) => count($r['cells']), $rows));
        $header = [];
        foreach ($rows as $r) {
            if (count($r['cells']) === $max && $max >= 2) { $header = $r['cells']; break; }
        }
        return ['id' => $id, 'pages' => [$page], 'start_line' => $start, 'header' => $header, 'rows' => $rows, 'text' => implode("\n", array_column($rows, 'line'))];
    }

    private function splitRow(string $line): array
    {
        if (str_contains($line, '|')) return $this->parts(preg_split('/\|/u', $line) ?: []);
        if (str_contains($line, "\t")) return $this->parts(preg_split('/\t+/u', $line) ?: []);
        $cells = preg_split('/\s{2,}/u', trim($line)) ?: [];
        if (count($cells) > 1) return $this->parts($cells);
        // Smalot bazı PDF'lerde kolon aralıklarını tek boşluğa indirger.
        // Bilinen attribute satırlarında label'ı ayırıp kalan hücreleri token olarak korur.
        if ($this->isAttributeRow($line)) {
            if (preg_match('/^(No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri(?: No)?|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Yer|Uzunluk|Tasarım Basıncı|Basınç|Basinç|Debi|Çap|Cap|Hortum Tipi|Tip|Tür|Tur|Pompa No|Ekipman No)\s+(.+)$/iu', $line, $m)) {
                return array_merge([trim($m[1])], preg_split('/\s+/u', trim($m[2])) ?: []);
            }
        }
        return [$line];
    }

    private function parts(array $parts): array
    {
        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $parts), fn ($v) => $v !== ''));
    }

    private function mergeContinuationTables(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            $last = count($out) - 1;
            if ($last >= 0 && $this->looksLikeContinuation($out[$last], $table)) {
                $out[$last]['rows'] = array_merge($out[$last]['rows'], $table['rows']);
                $out[$last]['pages'] = array_values(array_unique(array_merge($out[$last]['pages'], $table['pages'])));
                $out[$last]['text'] .= "\n" . $table['text'];
                if (count($out[$last]['header']) < count($table['header'])) $out[$last]['header'] = $table['header'];
            } else {
                $out[] = $table;
            }
        }
        return $out;
    }

    private function looksLikeContinuation(array $a, array $b): bool
    {
        if (min($b['pages']) - max($a['pages']) > 1) return false;
        $ah = $this->headerSignature($a['header']);
        $bh = $this->headerSignature($b['header']);
        if ($ah !== '' && $ah === $bh) return true;
        $aCode = $this->hasEquipmentCode($a['text']);
        $bCode = $this->hasEquipmentCode($b['text']);
        if ($aCode && $bCode && abs(count($a['header']) - count($b['header'])) <= 1) return true;
        return $aCode && $bCode && count($b['header']) === 0;
    }

    private function matchTables(array $tables, string $name, string $category): array
    {
        $nameTokens = $this->tokens($name);
        $result = [];
        foreach ($tables as $table) {
            $text = mb_strtolower($table['text'], 'UTF-8');
            $score = 0;
            foreach ($nameTokens as $token) if (mb_strlen($token) >= 3 && str_contains($text, $token)) $score += 2;
            foreach ($this->categoryWords($category) as $word) if (str_contains($text, $word)) $score++;
            if ($this->hasTechnicalColumns($table)) $score += 2;
            if ($this->hasControlMarkers($table['text'])) $score++;
            if ($this->hasEquipmentCode($table['text'])) $score++;
            if ($score >= 3) $result[] = $table;
        }
        return $result;
    }

    private function extractEquipmentMatrix(array $table): array
    {
        $codes = [];
        foreach ($table['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                if (preg_match_all('/\bYD\s*[- ]?\s*\d+[A-Z]?\b/iu', $cell, $m)) {
                    foreach ($m[0] as $code) $codes[] = strtoupper(preg_replace('/\s+/', '', str_replace('-', '', $code)));
                }
            }
            if (count($codes) >= 2) break;
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) return ['codes' => [], 'locations' => []];

        $attrs = $this->attributeRows($table);
        $locations = $this->findAttribute($attrs, ['kat', 'bulunduğu yer', 'bulundugu yer', 'lokasyon', 'konum', 'yer']);
        $outLocations = [];
        foreach ($codes as $i => $_) $outLocations[] = $this->clean($locations[$i] ?? null);
        return ['codes' => $codes, 'locations' => $outLocations];
    }

    private function extractComponents(array $table): array
    {
        $attrs = $this->attributeRows($table);
        $keys = [
            'code' => ['kod', 'no', 'ekipman no', 'pompa no', 'hidrant no', 'cihaz no', 'etiket'],
            'name' => ['ekipman', 'ekipman adi', 'ekipman adı', 'tip', 'tur', 'tür', 'pompa'],
            'location' => ['kat', 'bulunduğu yer', 'bulundugu yer', 'lokasyon', 'konum', 'yer'],
            'brand' => ['marka', 'üretici', 'uretici'],
            'model' => ['model', 'model no'],
            'serial_no' => ['seri no', 'serino', 'seri numarasi', 'seri numarası'],
        ];
        $values = [];
        foreach ($keys as $field => $aliases) $values[$field] = $this->findAttribute($attrs, $aliases);
        $technical = $attrs;
        foreach (['kod','no','ekipman no','pompa no','hidrant no','cihaz no','etiket','ekipman','ekipman adi','ekipman adı','kat','bulunduğu yer','bulundugu yer','lokasyon','konum','yer','marka','üretici','uretici','model','model no','seri no','serino','seri numarasi','seri numarası'] as $skip) unset($technical[$this->normalizeKey($skip)]);
        $count = max(array_map('count', $values));
        if ($count === 0) return [];
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $item = ['code'=> $this->clean($values['code'][$i] ?? null), 'name'=>$this->clean($values['name'][$i] ?? null), 'location'=>$this->clean($values['location'][$i] ?? null), 'brand'=>$this->clean($values['brand'][$i] ?? null), 'model'=>$this->clean($values['model'][$i] ?? null), 'serial_no'=>$this->clean($values['serial_no'][$i] ?? null), 'properties'=>$this->columnAt($technical, $i), 'source_pages'=>$table['pages']];
            if ($item['code'] !== null || $item['name'] !== null || $item['location'] !== null) $items[] = $item;
        }
        return $items;
    }

    private function extractControls(array $table): array
    {
        $out = [];
        foreach ($table['rows'] as $row) {
            if (!$this->isControlRow($row['line'])) continue;
            $label = trim((string) ($row['cells'][0] ?? $row['line']));
            preg_match('/^(\d+(?:\.\d+)*\.?)/u', $label, $m);
            $results = [];
            foreach (array_slice($row['cells'], 1) as $i => $cell) {
                $v = $this->status($cell);
                if ($v !== null) $results[(string) ($i + 1)] = $v;
            }
            preg_match_all('/\b(?:U|UD|N)\b/iu', $row['line'], $tokens);
            if ($results === [] && empty($tokens[0])) continue;
            $out[] = ['control_code'=>$m[1] ?? null, 'description'=>trim(preg_replace('/^\d+(?:\.\d+)*\.?\s*/u', '', $label)), 'results'=>$results, 'nonconforming_count'=>count(array_filter($results, fn($v)=>$v==='UD')), 'source_pages'=>$table['pages']];
        }
        return $out;
    }

    private function attributeRows(array $table): array
    {
        $out = [];
        foreach ($table['rows'] as $row) {
            $label = $this->cellLabel($row['cells'][0] ?? '');
            if ($label !== null && count($row['cells']) >= 2) $out[$this->normalizeKey($label)] = array_slice($row['cells'], 1);
        }
        return $out;
    }

    private function findAttribute(array $attrs, array $aliases): array
    {
        foreach ($aliases as $alias) {
            $key = $this->normalizeKey($alias);
            foreach ($attrs as $actual => $values) if ($actual === $key || str_contains($actual, $key) || str_contains($key, $actual)) return array_values($values);
        }
        return [];
    }

    private function columnAt(array $attrs, int $index): array
    {
        $out = [];
        foreach ($attrs as $key => $values) if (array_key_exists($index, $values)) $out[$key] = $values[$index];
        return $out;
    }

    private function tableForUi(array $t): array
    {
        return ['table_id'=>$t['id'], 'pages'=>$t['pages'], 'headers'=>$t['header'], 'rows'=>array_map(fn($r)=>$r['cells'],$t['rows']), 'raw_text'=>$t['text']];
    }

    private function mergeMatrix(array $a, array $b): array
    {
        foreach ($b['codes'] as $i => $code) {
            $pos = array_search($code, $a['codes'], true);
            if ($pos === false) { $a['codes'][] = $code; $a['locations'][] = $b['locations'][$i] ?? null; }
            elseif (($a['locations'][$pos] ?? null) === null) $a['locations'][$pos] = $b['locations'][$i] ?? null;
        }
        return $a;
    }

    private function pagesOfTables(array $tables): array
    {
        return array_values(array_unique(array_merge(...array_map(fn($t)=>$t['pages'], $tables ?: [['pages'=>[]]]))));
    }

    private function uniqueComponents(array $items): array
    {
        $out=[];$seen=[]; foreach($items as $item){$key=mb_strtolower(implode('|',[$item['code']??'',$item['name']??'',$item['location']??'',$item['brand']??'',$item['model']??'',$item['serial_no']??'']),'UTF-8'); if($key==='|||||'||isset($seen[$key]))continue;$seen[$key]=1;$out[]=$item;} return $out;
    }

    private function uniqueControls(array $items): array
    {
        $out=[];$seen=[]; foreach($items as $item){$key=mb_strtolower(($item['system_name']??'').'|'.($item['control_code']??'').'|'.($item['description']??''),'UTF-8');if(isset($seen[$key]))continue;$seen[$key]=1;$out[]=$item;}return $out;
    }

    private function uniqueSystems(array $items): array
    {
        $out=[];$seen=[];foreach($items as $item){$key=mb_strtolower(($item['name']??'').'|'.($item['category']??''),'UTF-8');if(isset($seen[$key]))continue;$seen[$key]=1;$out[]=$item;}return $out;
    }

    private function cellLabel(string $v): ?string
    {
        $v=trim($v); return preg_match('/^(No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri(?: No)?|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Yer|Uzunluk|Tasarım Basıncı|Basınç|Basinç|Debi|Çap|Cap|Hortum Tipi|Tip|Tür|Tur|Pompa No|Ekipman No)\b/iu',$v)?$v:null;
    }

    private function isAttributeRow(string $v): bool { return $this->cellLabel($v)!==null; }
    private function isControlRow(string $v): bool { return preg_match('/(?:^|\s)\d+(?:\.\d+)+\.?\s+.+\b(?:U|UD|N)\b/iu',trim($v))===1; }
    private function hasControlMarkers(string $v): bool { return preg_match('/\b(?:U|UD|N)\b/iu',$v)===1; }
    private function hasEquipmentCode(string $v): bool { return preg_match('/\b(?:YD\s*[- ]?\s*\d+|H\s*[- ]?\s*\d+|P\s*[- ]?\s*\d+)\b/iu',$v)===1; }
    private function hasTechnicalColumns(array $t): bool { return preg_match('/marka|model|seri|basınç|basinc|uzunluk|debi|çap|cap|kat|lokasyon|konum|hortum|tip|tür|tur/iu',implode(' ',$t['header']))===1; }
    private function isRelevantFireTable(array $t): bool { return $this->hasEquipmentCode($t['text'])||$this->hasControlMarkers($t['text'])||$this->hasTechnicalColumns($t); }
    private function status(string $v): ?string { $v=mb_strtoupper(trim($v),'UTF-8'); return in_array($v,['U','UD','N'],true)?$v:null; }
    private function clean(?string $v): ?string { if($v===null)return null;$v=trim(preg_replace('/\s+/u',' ',$v));return $v===''||$v==='-'?null:$v; }
    private function normalizeKey(string $v): string { $v=mb_strtolower(trim($v),'UTF-8');$v=strtr($v,['ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c']);return trim(preg_replace('/[^a-z0-9]+/u',' ',$v)??$v); }
    private function headerSignature(array $h): string { return implode('|',array_map(fn($v)=>$this->normalizeKey($v),$h)); }
    private function tokens(string $v): array { return array_values(array_unique(array_filter(explode(' ',$this->normalizeKey($v)),fn($x)=>mb_strlen($x)>=3))); }
    private function category(string $category,string $name): string { $v=$this->normalizeKey($category.' '.$name); return match(true){str_contains($v,'dolap')=>'yangin_dolabi',str_contains($v,'pompa')=>'yangin_pompasi',str_contains($v,'hidrant')=>'hidrant',str_contains($v,'sprinkler')||str_contains($v,'yagmurlama')=>'sprinkler',str_contains($v,'su depo')=>'su_deposu',str_contains($v,'su alma')||str_contains($v,'su verme')=>'su_alma_verme',str_contains($v,'boru')||str_contains($v,'kollektor')=>'sabit_boru_tesisati',str_contains($v,'gazli')||str_contains($v,'fm200')||str_contains($v,'co2')=>'gazli_sondurme',default=>$category!==''?$category:'diger'}; }
    private function categoryWords(string $c): array { return match($c){'yangin_dolabi'=>['dolap','hortum'],'yangin_pompasi'=>['pompa','jokey','debi'],'hidrant'=>['hidrant'],'sprinkler'=>['sprinkler','yagmurlama'],'su_deposu'=>['depo'],'su_alma_verme'=>['su alma','su verme','itfaiye'],'sabit_boru_tesisati'=>['boru','kollektor','vana'],'gazli_sondurme'=>['gazli','fm200','co2'],default=>[]}; }
}
