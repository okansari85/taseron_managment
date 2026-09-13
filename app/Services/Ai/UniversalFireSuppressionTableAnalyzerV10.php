<?php
namespace App\Services\Ai;

/**
 * Universal fire-suppression report table normalizer.
 *
 * Responsibilities:
 * - preserve Gemini's semantic systems/findings
 * - extract physical equipment from tabular report data
 * - detect whether control results are system-scoped or equipment-scoped
 * - link explicit equipment references from findings
 * - keep system-level controls/findings separate from equipment
 * - emit one clean JSON shape; no matrices/raw tables in the final UI payload
 *
 * No firm/template-specific rules are used.
 */
class UniversalFireSuppressionTableAnalyzerV10 extends UniversalFireSuppressionTableAnalyzerV8
{
    public function analyze(array $pages, array $semantic): array
    {
        $base = parent::analyze($pages, $semantic);

        $equipment = $this->mergeEquipment(
            (array)($base['equipment'] ?? []),
            $this->extractWideEquipment($pages, (array)($semantic['systems'] ?? []))
        );
        $equipment = $this->normalizeEquipment($equipment, (array)($base['control_matrix'] ?? []));

        $systemControls = $this->extractSystemControls($pages, (array)($semantic['systems'] ?? []));
        $equipmentControls = $this->extractEquipmentControls($pages, $equipment);
        $equipment = $this->applyEquipmentControls($equipment, $equipmentControls);

        $findings = $this->normalizeFindings((array)($semantic['findings'] ?? []), $equipment);
        $equipment = $this->applyFindingLinks($equipment, $findings);

        $systems = [];
        foreach ((array)($semantic['systems'] ?? []) as $semanticSystem) {
            if (!is_array($semanticSystem)) continue;

            $name = trim((string)($semanticSystem['name'] ?? ''));
            if ($name === '') continue;
            $category = $this->categoryOf((string)($semanticSystem['category'] ?? ''), $name);
            $key = $this->systemKey($name, $category);

            $components = array_values(array_filter(
                $equipment,
                fn(array $item) => $this->sameSystem($item, $name, $category)
            ));

            $controls = array_values($systemControls[$key] ?? []);
            if (!$controls) $controls = $this->controlsFromEquipment($components);

            $systemFindings = array_values(array_filter(
                $findings,
                fn(array $finding) => $this->findingMatchesSystem($finding, $name, $category)
            ));

            $nonconformingEquipment = count(array_filter(
                $components,
                fn(array $item) => ($item['status'] ?? 'belirtilmemis') === 'uygun_degil'
            ));
            $nonconformingControls = count(array_filter(
                $controls,
                fn(array $control) => ($control['status'] ?? null) === 'UD'
            ));

            $systems[] = [
                'name' => $name,
                'category' => $category,
                'status' => $this->systemStatus($components, $controls, $systemFindings),
                'equipment_count' => count($components),
                'equipment_count_known' => count($components) > 0,
                'nonconforming_equipment_count' => $nonconformingEquipment,
                'control_count' => count($controls),
                'nonconforming_count' => $nonconformingControls,
                'components' => array_map(function (array $item): array {
                    return [
                        'code' => $item['code'] ?? null,
                        'name' => $item['name'] ?? null,
                        'location' => $item['location_note'] ?? null,
                        'brand' => $item['brand'] ?? null,
                        'model' => $item['model'] ?? null,
                        'serial_no' => $item['serial_no'] ?? null,
                        'status' => $item['status'] ?? 'belirtilmemis',
                        'status_source' => $item['status_source'] ?? null,
                        'control_refs' => array_values($item['control_refs'] ?? []),
                        'finding_refs' => array_values($item['finding_refs'] ?? []),
                        'properties' => (array)($item['properties'] ?? []),
                        'source_pages' => array_values($item['source_pages'] ?? []),
                    ];
                }, $components),
                'control_items' => array_values(array_map(function (array $control): array {
                    return [
                        'code' => $control['code'] ?? null,
                        'description' => $control['description'] ?? null,
                        'status' => $control['status'] ?? null,
                        'scope' => $control['scope'] ?? 'system',
                        'equipment_refs' => array_values($control['equipment_refs'] ?? []),
                        'source_pages' => array_values($control['source_pages'] ?? []),
                    ];
                }, $controls)),
                'findings' => $systemFindings,
            ];
        }

        $report = (array)($semantic['report'] ?? []);
        return [
            'report' => [
                'report_no' => $report['report_no'] ?? null,
                'company_name' => $report['company_name'] ?? null,
                'control_date' => $report['control_date'] ?? null,
                'next_control_date' => $report['next_control_date'] ?? null,
                'overall_result' => $report['overall_result'] ?? null,
            ],
            'covered_categories' => array_values(array_unique(array_filter(
                array_map(fn(array $s) => $s['category'] ?? null, $systems)
            ))),
            'systems' => $systems,
            'equipment' => array_values($equipment),
            'findings' => $findings,
            'matched_inventory_items' => (array)($base['matched_inventory_items'] ?? []),
            'candidate_inventory_items' => (array)($base['candidate_inventory_items'] ?? []),
            'unmatched_codes' => (array)($base['unmatched_codes'] ?? []),
            'analyzer' => [
                'version' => '10.0.0',
                'table_count' => (int)($base['analyzer']['table_count'] ?? 0),
                'equipment_count' => count($equipment),
                'control_count' => array_sum(array_map(
                    fn(array $system) => (int)($system['control_count'] ?? 0),
                    $systems
                )),
                'finding_count' => count($findings),
            ],
        ];
    }

    private function extractWideEquipment(array $pages, array $semanticSystems): array
    {
        $definitions = [];
        foreach ($semanticSystems as $system) {
            if (!is_array($system)) continue;
            $name = trim((string)($system['name'] ?? ''));
            if ($name === '') continue;
            $category = $this->categoryOf((string)($system['category'] ?? ''), $name);
            $definitions[] = [
                'name' => $name,
                'category' => $category,
                'key' => $this->systemKey($name, $category),
                'tokens' => $this->tokens($name, $category),
            ];
        }

        $out = [];
        $context = null;
        $pending = null;

        foreach (array_values($pages) as $pageIndex => $page) {
            $lines = preg_split('/\R/u', (string)$page) ?: [];
            foreach ($lines as $raw) {
                $line = trim((string)$raw);
                if ($line === '') continue;

                $heading = $this->matchEquipmentSection($line, $definitions);
                if ($heading !== null) {
                    if ($pending !== null) $out = array_merge($out, $this->materializeEquipmentBlock($pending));
                    $context = $heading;
                    $pending = null;
                    continue;
                }

                if ($this->looksLikeEndOfEquipmentSection($line)) {
                    if ($pending !== null) $out = array_merge($out, $this->materializeEquipmentBlock($pending));
                    $context = null;
                    $pending = null;
                    continue;
                }
                if ($context === null) continue;

                $parsed = $this->parseEquipmentRow($line);
                if ($parsed !== null) {
                    if ($this->isIdentityLabel($parsed['label'])) {
                        if ($pending !== null) $out = array_merge($out, $this->materializeEquipmentBlock($pending));
                        $pending = [
                            'page' => $pageIndex + 1,
                            'system' => $context,
                            'fields' => [],
                            'codes' => $this->parseIdentityValues($parsed['values']),
                        ];
                        $pending['fields'][$parsed['label']] = $parsed['values'];
                    } elseif ($pending !== null) {
                        $pending['fields'][$parsed['label']] = $parsed['values'];
                    }
                    continue;
                }

                if ($pending !== null && $this->isLikelyCellContinuation($line, $pending)) {
                    $label = $this->lastFieldLabel($pending['fields']);
                    if ($label !== null) $pending['fields'][$label] .= ' ' . $line;
                }
            }

            if ($pending !== null) {
                $out = array_merge($out, $this->materializeEquipmentBlock($pending));
                $pending = null;
            }
        }
        return $out;
    }

    private function parseEquipmentRow(string $line): ?array
    {
        $labels = [
            'Dolap No'=>'dolap no','Pompa No'=>'pompa no','Hidrant No'=>'hidrant no','Ekipman No'=>'ekipman no',
            'Cihaz No'=>'cihaz no','No / Kod'=>'no / kod','No/Kod'=>'no/kod','Kod'=>'kod','Marka'=>'marka',
            'Üretici'=>'uretici','Model No'=>'model no','Model'=>'model','Seri No'=>'seri no','Serino'=>'serino',
            'Seri Numarası'=>'seri numarasi','Bulunduğu Yer'=>'bulundugu yer','Bulundugu Yer'=>'bulundugu yer',
            'Lokasyon'=>'lokasyon','Konum'=>'konum','Yer'=>'yer','Ölçülen Basınç'=>'olculen basinc',
            'Olculen Basinc'=>'olculen basinc','Basınç'=>'basinc','Basinç'=>'basinc','Hortum Uzunluğu'=>'hortum uzunlugu',
            'Hortum Uzunlugu'=>'hortum uzunlugu','Dolaplar Arası Mesafe'=>'dolaplar arasi mesafe',
            'Dolaplar Arasi Mesafe'=>'dolaplar arasi mesafe','Debi'=>'debi','Çap'=>'cap','Cap'=>'cap','Güç'=>'guc',
            'Guc'=>'guc','Tip'=>'tip','Tür'=>'tur','Tur'=>'tur','Yakıt'=>'yakit','Yakit'=>'yakit'
        ];

        $clean = preg_replace('/^\s*[A-ZÇĞİÖŞÜ]{1,3}\s+/u', '', trim($line));
        $clean = trim((string)$clean);
        foreach ($labels as $label => $canonical) {
            if (preg_match('/^' . preg_quote($label, '/') . '(?:\s+(.*))?$/iu', $clean, $m)) {
                return ['label'=>$canonical,'values'=>trim((string)($m[1] ?? ''))];
            }
        }
        return null;
    }

    private function materializeEquipmentBlock(array $block): array
    {
        $codes = $block['codes'] ?? [];
        if (!$codes) return [];
        $fields = $block['fields'] ?? [];
        $system = $block['system'] ?? [];
        $page = (int)($block['page'] ?? 0);
        $count = count($codes);
        $records = [];

        foreach ($codes as $index => $code) {
            $properties = [];
            foreach ($fields as $label => $value) {
                if (in_array($label, ['dolap no','pompa no','hidrant no','ekipman no','cihaz no','no / kod','no/kod','kod','marka','uretici','model','model no','seri no','serino','seri numarasi','bulundugu yer','lokasyon','konum','yer'], true)) continue;
                $properties[$label] = $this->fieldAtValue($value, $index, $count);
            }
            $records[] = [
                'code'=>$code,
                'category'=>$system['category'] ?? 'diger',
                'system_name'=>$system['name'] ?? null,
                'system_category'=>$system['category'] ?? 'diger',
                'location_note'=>$this->fieldAt($fields, ['bulundugu yer','lokasyon','konum','yer'], $index, $count),
                'brand'=>$this->fieldAt($fields, ['marka','uretici'], $index, $count),
                'model'=>$this->fieldAt($fields, ['model no','model'], $index, $count),
                'serial_no'=>$this->fieldAt($fields, ['seri no','serino','seri numarasi'], $index, $count),
                'result'=>null,
                'status'=>'belirtilmemis','status_source'=>null,'note'=>null,
                'control_items'=>[],'control_refs'=>[],'finding_refs'=>[],
                'properties'=>$properties,'source_pages'=>$page ? [$page] : [],
                'match'=>['status'=>'new','matched_id'=>null,'candidate_ids'=>[]],
            ];
        }
        return $records;
    }

    private function parseIdentityValues(string $value): array
    {
        $value = trim($value);
        if ($value === '') return [];
        preg_match_all('/\b(?:YD\s*[- ]?\s*\d+[A-Z]?|HD\s*[- ]?\s*\d+[A-Z]?|H\s*[- ]?\s*\d+[A-Z]?|P\s*[- ]?\s*\d+[A-Z]?|\d+[A-Z]?)\b/iu', $value, $m);
        $codes = [];
        foreach ($m[0] ?? [] as $raw) {
            $code = preg_replace('/\s+/u','',strtoupper(trim((string)$raw)));
            if ($code !== '') $codes[] = $code;
        }
        return array_values(array_unique($codes));
    }

    private function fieldAt(array $fields, array $labels, int $index, int $count): ?string
    {
        foreach ($labels as $label) if (array_key_exists($label, $fields)) return $this->fieldAtValue($fields[$label], $index, $count);
        return null;
    }

    private function fieldAtValue(string $value, int $index, int $count): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $parts = preg_split('/\s{2,}|\t+/u', $value) ?: [];
        $parts = array_values(array_filter(array_map('trim',$parts),fn($v)=>$v!==''));
        if (count($parts) === $count) return $parts[$index] ?? null;
        if (count($parts) === 1 && preg_match('/^(?:-+|[0-9.,]+)(?:\s+(?:-+|[0-9.,]+)){'.($count-1).'}$/u',$value)) {
            $flat = preg_split('/\s+/u',$value) ?: [];
            return trim((string)($flat[$index] ?? ''));
        }
        if ($count === 1) return $value;
        return null;
    }

    private function matchEquipmentSection(string $line, array $definitions): ?array
    {
        $normalized = $this->normalizeKey($line);
        if (!preg_match('/\b(?:liste|listesi|ekipman|dolap|pompa|hidrant|sprinkler)\b/iu',$line)) return null;
        $best=null;$bestScore=0;
        foreach ($definitions as $definition) {
            $score=0;
            foreach ($definition['tokens'] as $token) if (mb_strlen($token,'UTF-8')>=4 && str_contains($normalized,$token)) $score++;
            if ($score>$bestScore) {$bestScore=$score;$best=$definition;}
        }
        return $bestScore>=1 ? $best : null;
    }

    private function looksLikeEndOfEquipmentSection(string $line): bool
    {
        return preg_match('/^\s*(?:\d+(?:\.\d+)*\s+)?(?:muayene kriterleri|kontrol kriterleri|kusur|bulgular|sonuc|sonuç|genel bilgiler|notlar)\b/iu',$line)===1;
    }

    private function isIdentityLabel(string $label): bool
    {
        return in_array($label,['dolap no','pompa no','hidrant no','ekipman no','cihaz no','no / kod','no/kod','kod'],true);
    }

    private function isLikelyCellContinuation(string $line, array $pending): bool
    {
        if ($line==='') return false;
        if (preg_match('/^(?:[A-ZÇĞİÖŞÜ]{1,3}\s+)?(?:dolap no|pompa no|hidrant no|marka|model|seri no|bulundugu yer|lokasyon|konum|basinc|olculen basinc|hortum|debi|cap|guc)\b/iu',$line)) return false;
        return $this->lastFieldLabel($pending['fields'] ?? []) !== null;
    }

    private function lastFieldLabel(array $fields): ?string { return $fields ? array_key_last($fields) : null; }

    private function mergeEquipment(array $base, array $extra): array
    {
        $groups=[];
        foreach (array_merge($base,$extra) as $item) {
            if (!is_array($item)) continue;
            $code=trim((string)($item['code']??''));
            if ($code==='') continue;
            $groups[$this->normalizeCode($code)][]=$item;
        }
        $out=[];
        foreach ($groups as $items) {
            usort($items,fn(array $a,array $b)=>$this->equipmentRichness($b)<=>$this->equipmentRichness($a));
            $winner=$items[0];
            foreach ($items as $item) {
                foreach (['location_note','brand','model','serial_no'] as $field) if (empty($winner[$field])&&!empty($item[$field])) $winner[$field]=$item[$field];
                if (empty($winner['properties'])&&!empty($item['properties'])) $winner['properties']=$item['properties'];
                $winner['source_pages']=array_values(array_unique(array_merge((array)($winner['source_pages']??[]),(array)($item['source_pages']??[]))));
                $winner['control_items']=array_merge((array)($winner['control_items']??[]),(array)($item['control_items']??[]));
                $winner['control_refs']=array_values(array_unique(array_merge((array)($winner['control_refs']??[]),(array)($item['control_refs']??[]))));
                $winner['finding_refs']=array_values(array_unique(array_merge((array)($winner['finding_refs']??[]),(array)($item['finding_refs']??[]))));
            }
            $out[]=$winner;
        }
        return $out;
    }

    private function normalizeEquipment(array $equipment, array $controlMatrix=[]): array
    {
        $descriptions=[];
        foreach ($controlMatrix as $control) {
            if (!is_array($control)) continue;
            $code=trim((string)($control['control_code']??$control['code']??''));
            if ($code!=='') $descriptions[$code]=$control['description']??null;
        }
        return array_values(array_map(function(array $item)use($descriptions):array{
            $item['code']=trim((string)($item['code']??''));
            $rawControls=$item['control_items']??[];$controls=[];
            foreach ((array)$rawControls as $key=>$raw) {
                if (is_array($raw)) {$code=trim((string)($raw['code']??$key));$statusRaw=$raw['status']??null;$description=$raw['description']??($descriptions[$code]??null);}
                else {$code=trim((string)$key);$statusRaw=$raw;$description=$descriptions[$code]??null;}
                $status=strtoupper(str_replace('.','',trim((string)$statusRaw)));
                if ($code===''||!in_array($status,['U','UD','N'],true)) continue;
                $controls[]=['code'=>$code,'status'=>$status,'description'=>$description,'scope'=>'equipment','equipment_refs'=>[$item['code']],'source_pages'=>array_values($item['source_pages']??[])];
            }
            $item['control_items']=$controls;
            $item['control_refs']=array_values(array_unique(array_merge((array)($item['control_refs']??[]),array_map(fn(array $c)=>$c['code'],$controls))));
            $hasUd=count(array_filter($controls,fn(array $c)=>$c['status']==='UD'))>0;
            $hasU=count(array_filter($controls,fn(array $c)=>$c['status']==='U'))>0;
            if($hasUd){$item['status']='uygun_degil';$item['status_source']='control';}
            elseif($hasU){$item['status']='uygun';$item['status_source']='control';}
            else{$item['status']=$item['status']??'belirtilmemis';if(!in_array($item['status'],['uygun','uygun_degil','belirtilmemis'],true))$item['status']='belirtilmemis';}
            $item['finding_refs']=array_values(array_unique((array)($item['finding_refs']??[])));
            $item['source_pages']=array_values(array_unique((array)($item['source_pages']??[])));
            return $item;
        },array_filter($equipment,fn($item)=>is_array($item)&&trim((string)($item['code']??''))!=='')));
    }

    private function extractEquipmentControls(array $pages,array $equipment):array
    {
        $byCode=[];
        foreach($equipment as $item){$code=$this->normalizeCode((string)($item['code']??''));if($code!=='')$byCode[$code]=$item['code'];}
        if(!$byCode)return [];
        $out=[];$activeCodes=[];
        foreach($pages as $pageIndex=>$page){
            foreach(preg_split('/\R/u',(string)$page)?:[] as $raw){
                $line=trim((string)$raw);if($line==='')continue;
                $found=$this->extractEquipmentCodesFromText($line,$byCode);
                if(count($found)>=2){$activeCodes=$found;continue;}
                if(!$activeCodes)continue;
                foreach($this->parseControlSegmentsWithEquipmentStatuses($line,$activeCodes) as $segment)$out[]=$segment+['source_pages'=>[$pageIndex+1]];
                if($this->looksLikeNewSection($line))$activeCodes=[];
            }
        }
        return $out;
    }

    private function extractEquipmentCodesFromText(string $line,array $known):array
    {
        $found=[];
        foreach($known as $normalized=>$original){
            if(preg_match('/(?<![A-Za-z0-9])'.preg_quote($original,'/').'(?!(?:[A-Za-z0-9]))/iu',$line))$found[]=$original;
        }
        return array_values(array_unique($found));
    }

    private function parseControlSegmentsWithEquipmentStatuses(string $line,array $codes):array
    {
        if(!preg_match('/(?<![A-Za-z0-9])([A-ZÇĞİÖŞÜ]{1,3}\.\d+|\d+\.\d+)(?=[\s\.)])/u',$line))return [];
        preg_match_all('/(?<![A-Za-z])(?:U\.?D|U|N)(?![A-Za-z])/iu',$line,$m);
        $statuses=[];foreach($m[0]??[] as $raw){$status=strtoupper(str_replace('.','',trim((string)$raw)));if(in_array($status,['U','UD','N'],true))$statuses[]=$status;}
        if(!$statuses)return [];
        preg_match_all('/(?<![A-Za-z0-9])([A-ZÇĞİÖŞÜ]{1,3}\.\d+|\d+\.\d+)(?=[\s\.)])/u',$line,$cm,PREG_OFFSET_CAPTURE);
        $out=[];foreach($cm[1] as $i=>$match){$status=$statuses[$i]??null;if($status===null)continue;$out[]=['code'=>(string)$match[0],'status'=>$status,'scope'=>'equipment','equipment_refs'=>array_values($codes),'description'=>null];}
        return $out;
    }

    private function applyEquipmentControls(array $equipment,array $controls):array
    {
        foreach($equipment as &$item){
            $itemControls=[];
            foreach($controls as $control)if(in_array($item['code'],(array)($control['equipment_refs']??[]),true))$itemControls[]=$control;
            if(!$itemControls)continue;
            $item['control_items']=array_values(array_merge((array)($item['control_items']??[]),$itemControls));
            foreach($itemControls as $control)$item['control_refs'][]=$control['code'];
            $item['control_refs']=array_values(array_unique($item['control_refs']));
            if(array_filter($itemControls,fn(array $c)=>($c['status']??null)==='UD')){$item['status']='uygun_degil';$item['status_source']=$item['status_source']?'control_and_'.$item['status_source']:'control';}
            elseif(($item['status']??'belirtilmemis')==='belirtilmemis'){$item['status']='uygun';$item['status_source']='control';}
        }
        unset($item);return $equipment;
    }

    private function normalizeFindings(array $findings,array $equipment):array
    {
        $known=[];foreach($equipment as $item){$code=trim((string)($item['code']??''));if($code!=='')$known[$this->normalizeCode($code)]=$code;}
        $out=[];foreach($findings as $index=>$finding){if(!is_array($finding))continue;$description=trim((string)($finding['description']??''));if($description==='')continue;$out[]=['id'=>(string)($finding['id']??('finding-'.($index+1))),'system_name'=>$finding['system_name']??null,'description'=>$description,'equipment_refs'=>$this->extractExplicitEquipmentRefs($description,$known)];}
        return $out;
    }

    private function extractExplicitEquipmentRefs(string $text,array $known):array
    {
        $refs=[];
        foreach($known as $normalized=>$original)if(preg_match('/(?<![A-Za-z0-9])'.preg_quote($original,'/').'(?<![A-Za-z0-9])/iu',$text))$refs[]=$original;
        if(preg_match_all('/\b(YD|HD|H|P)\s*[- ]?\s*(\d+)\s*(?:-|–|—|ile)\s*(?:\1\s*[- ]?\s*)?(\d+)\b/iu',$text,$m)){
            foreach($m[2] as $i=>$start){$prefix=strtoupper($m[1][$i]);$end=(int)$m[3][$i];for($n=(int)$start;$n<=$end;$n++){ $candidate=$prefix.$n;$key=$this->normalizeCode($candidate);if(isset($known[$key]))$refs[]=$known[$key]; }}
        }
        if(preg_match_all('/\b(YD|HD|H|P)\s*[- ]?\s*(\d+(?:\s*[-,]\s*\d+)+)\b/iu',$text,$m)){
            foreach($m[2] as $i=>$numbers){$prefix=strtoupper($m[1][$i]);preg_match_all('/\d+/',$numbers,$nums);foreach($nums[0]??[] as $number){$candidate=$prefix.(int)$number;$key=$this->normalizeCode($candidate);if(isset($known[$key]))$refs[]=$known[$key];}}
        }
        return array_values(array_unique($refs));
    }

    private function applyFindingLinks(array $equipment,array $findings):array
    {
        foreach($equipment as &$item){$code=$item['code']??null;if(!$code)continue;foreach($findings as $finding){if(!in_array($code,(array)($finding['equipment_refs']??[]),true))continue;$item['finding_refs'][]=$finding['id'];if(($item['status']??'belirtilmemis')!=='uygun_degil'){$item['status']='uygun_degil';$item['status_source']=$item['status_source']?'control_and_finding':'finding';}elseif(($item['status_source']??null)==='control')$item['status_source']='control_and_finding';}$item['finding_refs']=array_values(array_unique($item['finding_refs']));}
        unset($item);return $equipment;
    }

    private function extractSystemControls(array $pages,array $semanticSystems):array
    {
        $definitions=[];foreach($semanticSystems as $system){if(!is_array($system))continue;$name=trim((string)($system['name']??''));if($name==='')continue;$category=$this->categoryOf((string)($system['category']??''),$name);$definitions[]=['name'=>$name,'category'=>$category,'key'=>$this->systemKey($name,$category),'tokens'=>$this->tokens($name,$category)];}
        $out=[];$current=null;
        foreach(array_values($pages) as $pageIndex=>$page){foreach(preg_split('/\R/u',(string)$page)?:[] as $raw){$line=trim((string)$raw);if($line==='')continue;$section=$this->matchControlSection($line,$definitions);if($section!==null)$current=$section;if($this->looksLikeEndOfControlSection($line)){$current=null;continue;}if($current===null)continue;foreach($this->parseSystemControlLine($line) as $control){$control['scope']='system';$control['equipment_refs']=[];$control['source_pages']=[$pageIndex+1];$out[$current['key']][]=$control;}}}
        foreach($out as $key=>$controls){$unique=[];foreach($controls as $control){$k=($control['code']??'').'|'.$this->normalizeKey((string)($control['description']??''));if(!isset($unique[$k]))$unique[$k]=$control;else$unique[$k]['source_pages']=array_values(array_unique(array_merge((array)$unique[$k]['source_pages'],(array)$control['source_pages'])));} $out[$key]=array_values($unique);}
        return $out;
    }

    private function matchControlSection(string $line,array $definitions):?array
    {
        if(preg_match('/^\s*[A-ZÇĞİÖŞÜ]{1,2}\s+(.+?)\s+Durum(?:\s|$)/iu',$line,$m)){$heading=$this->normalizeKey((string)$m[1]);$best=null;$scoreBest=0;foreach($definitions as $definition){$score=0;foreach($definition['tokens'] as $token)if(mb_strlen($token,'UTF-8')>=4&&str_contains($heading,$token))$score++;if($score>$scoreBest){$scoreBest=$score;$best=$definition;}}if($best!==null&&$scoreBest>=1)return $best;}
        $normalized=$this->normalizeKey($line);$best=null;$bestScore=0;foreach($definitions as $definition){$score=0;foreach($definition['tokens'] as $token)if(mb_strlen($token,'UTF-8')>=5&&str_contains($normalized,$token))$score++;if($score>$bestScore){$bestScore=$score;$best=$definition;}}return $bestScore>=2?$best:null;
    }

    private function parseSystemControlLine(string $line):array
    {
        if(!preg_match_all('/(?<![A-Za-z0-9])([A-ZÇĞİÖŞÜ]{1,3}\.\d+|\d+\.\d+)(?=[\s\.)])/u',$line,$m,PREG_OFFSET_CAPTURE))return [];
        $out=[];foreach($m[1] as $i=>$match){$code=trim((string)$match[0]);$start=$match[1]+strlen($match[0]);$end=isset($m[1][$i+1])?$m[1][$i+1][1]:strlen($line);$body=trim(substr($line,$start,$end-$start));if(!preg_match('/(?<![A-Za-z])((?:U\.?D)|U|N)(?![A-Za-z])/iu',$body,$sm,PREG_OFFSET_CAPTURE))continue;$status=strtoupper(str_replace('.','',trim((string)$sm[1][0])));if(!in_array($status,['U','UD','N'],true))continue;$description=trim(substr($body,0,$sm[1][1]));$description=preg_replace('/^[*]+\s*/u','',$description);$description=preg_replace('/\s+/u',' ',(string)$description);$out[]=['code'=>$code,'description'=>trim((string)$description),'status'=>$status];}return $out;
    }

    private function controlsFromEquipment(array $components):array
    {
        $byCode=[];foreach($components as $item){foreach((array)($item['control_items']??[]) as $control){if(!is_array($control))continue;$code=(string)($control['code']??'');if($code==='')continue;if(!isset($byCode[$code]))$byCode[$code]=['code'=>$code,'description'=>$control['description']??null,'status'=>$control['status']??null,'scope'=>'equipment','equipment_refs'=>[],'source_pages'=>[]];$byCode[$code]['equipment_refs'][]=$item['code'];$byCode[$code]['equipment_refs']=array_values(array_unique($byCode[$code]['equipment_refs']));$byCode[$code]['source_pages']=array_values(array_unique(array_merge($byCode[$code]['source_pages'],(array)($item['source_pages']??[]))));if(($control['status']??null)==='UD')$byCode[$code]['status']='UD';}}return array_values($byCode);
    }

    private function findingMatchesSystem(array $finding,string $name,string $category):bool
    {
        $findingName=trim((string)($finding['system_name']??''));if($findingName==='')return false;
        return $this->normalizeKey($findingName)===$this->normalizeKey($name)||$this->categoryOf('', $findingName)===$category;
    }

    private function systemStatus(array $components,array $controls,array $findings):string
    {
        if(count(array_filter($components,fn(array $item)=>($item['status']??null)==='uygun_degil'))>0)return 'uygun_degil';
        if(count(array_filter($controls,fn(array $control)=>($control['status']??null)==='UD'))>0)return 'uygun_degil';
        if($findings)return 'uygun_degil';
        if($controls)return 'uygun';
        return 'belirtilmemis';
    }

    private function sameSystem(array $item,string $name,string $category):bool
    {
        $itemCategory=(string)($item['category']??$item['system_category']??'');$itemName=trim((string)($item['system_name']??''));
        if($itemCategory!==$category)return false;if($itemName===''||$name==='')return true;return $this->normalizeKey($itemName)===$this->normalizeKey($name);
    }

    private function categoryOf(string $category,string $name):string
    {
        if(trim($category)!=='')return trim($category);$n=$this->normalizeKey($name);
        return match(true){str_contains($n,'yangin dolab')||str_contains($n,'yangin hortum')=>'yangin_dolabi',str_contains($n,'pompa')=>'yangin_pompasi',str_contains($n,'su depos')||$n==='su deposu'=>'su_deposu',str_contains($n,'sprinkler')||str_contains($n,'yagmurlama')=>'sprinkler',str_contains($n,'hidrant')=>'hidrant',str_contains($n,'gazli')&&str_contains($n,'sondur')=>'gazli_sondurme',default=>'diger'};
    }

    private function tokens(string $name,string $category):array
    {
        $tokens=array_values(array_filter(preg_split('/\s+/u',$this->normalizeKey($name))?:[],fn($v)=>mb_strlen($v,'UTF-8')>=4));
        $extra=match($category){'yangin_dolabi'=>['yangin','dolabi','dolap','hortum'],'yangin_pompasi'=>['yangin','pompa'],'su_deposu'=>['su','deposu','depo'],'sprinkler'=>['sprinkler','yagmurlama'],'hidrant'=>['hidrant'],'gazli_sondurme'=>['gazli','sondurme'],default=>[]};
        return array_values(array_unique(array_merge($tokens,$extra)));
    }

    private function systemKey(string $name,string $category):string{return $this->normalizeKey($name).'|'.$category;}
    private function normalizeCode(string $value):string{return preg_replace('/[^A-Z0-9]/','',strtoupper(trim($value)))??'';}
    private function normalizeKey(string $value):string{return strtr(mb_strtolower(trim($value),'UTF-8'),['ç'=>'c','ğ'=>'g','ı'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','â'=>'a','î'=>'i','û'=>'u']);}
    private function equipmentRichness(array $item):int{$score=0;foreach(['location_note','brand','model','serial_no'] as $field)if(!empty($item[$field]))$score+=10;$score+=count((array)($item['properties']??[]))*2;$score+=count((array)($item['control_items']??[]))*2;$score+=count((array)($item['source_pages']??[]));return $score;}
    private function looksLikeNewSection(string $line):bool{return preg_match('/^\s*[A-ZÇĞİÖŞÜ]{1,2}\s+.+\bDurum\b/iu',$line)===1;}
    private function looksLikeEndOfControlSection(string $line):bool{return preg_match('/^\s*(?:\d+\.?\s*)?(?:8\.?\s*)?(?:kusur açıklamaları|kusur aciklamalari|bulgular|sonuç ve kanaat|sonuc ve kanaat|notlar?)\b/iu',$line)===1;}
}
