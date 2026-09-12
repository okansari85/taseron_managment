<?php

namespace App\Services\Ai;

/**
 * Universal, firm-independent PDF table analyzer.
 * Gemini provides semantics; this class deterministically maps table cells
 * to equipment/components and preserves technical/control values.
 */
class UniversalFireSuppressionTableAnalyzerV3
{
    public function analyze(array $pages, array $semantic): array
    {
        $tables = $this->discover($pages);
        $systems = [];
        $allEquipment = [];
        $allControls = [];
        $used = [];

        foreach ((array) ($semantic['systems'] ?? []) as $semanticSystem) {
            if (!is_array($semanticSystem)) continue;
            $name = trim((string) ($semanticSystem['name'] ?? ''));
            $category = $this->category((string) ($semanticSystem['category'] ?? ''), $name);
            $matched = $this->matchTables($tables, $name, $category);
            $used = array_merge($used, array_column($matched, 'id'));

            $components = [];
            $controls = [];
            foreach ($matched as $table) {
                $components = array_merge(
                    $components,
                    $this->isCabinetTable($table, $category)
                        ? $this->extractCabinetComponents($table)
                        : $this->extractGenericComponents($table, $name)
                );
                $controls = array_merge($controls, $this->extractControls($table));
            }

            $components = $this->uniqueComponents($components);
            $controls = $this->uniqueControls($controls);

            foreach ($components as $component) {
                $allEquipment[] = [
                    'code' => $component['code'] ?? $component['name'] ?? null,
                    'category' => $category,
                    'system_name' => $name ?: null,
                    'system_category' => $category,
                    'location_note' => $component['location'] ?? null,
                    'brand' => $component['brand'] ?? null,
                    'model' => $component['model'] ?? null,
                    'serial_no' => $component['serial_no'] ?? null,
                    'result' => null,
                    'note' => null,
                    'control_items' => $component['control_items'] ?? [],
                    'properties' => $component['properties'] ?? [],
                    'source_pages' => $component['source_pages'] ?? [],
                ];
            }
            foreach ($controls as $control) {
                $control['system_name'] = $name ?: null;
                $control['category'] = $category;
                $allControls[] = $control;
            }

            $systems[] = [
                'name' => $name ?: null,
                'category' => $category,
                'control_count' => count($controls),
                'nonconforming_count' => count(array_filter($controls, fn ($x) => ($x['nonconforming_count'] ?? 0) > 0)),
                'components' => $components,
                // Compatibility only. components[] is the authoritative model.
                'equipment_matrix' => [
                    'codes' => array_values(array_map(fn ($x) => $x['code'] ?? null, $components)),
                    'locations' => array_values(array_map(fn ($x) => $x['location'] ?? null, $components)),
                ],
                'tables' => array_map(fn ($t) => $this->uiTable($t), $matched),
                'control_items' => $controls,
            ];
        }

        return [
            'systems' => $this->uniqueSystems($systems),
            'equipment' => $this->uniqueComponents($allEquipment),
            'control_matrix' => $this->uniqueControls($allControls),
            'tables' => array_values(array_map(
                fn ($t) => $this->uiTable($t),
                array_filter($tables, fn ($t) => !in_array($t['id'], $used, true) && $this->relevant($t))
            )),
            'analyzer' => [
                'version' => '3.0.0',
                'table_count' => count($tables),
                'equipment_count' => count($allEquipment),
                'control_count' => count($allControls),
            ],
        ];
    }

    private function discover(array $pages): array
    {
        $tables = [];
        $id = 1;
        foreach (array_values($pages) as $pageIndex => $page) {
            $rows = [];
            foreach (preg_split('/\R/u', (string) $page) ?: [] as $lineNo => $raw) {
                $line = trim((string) $raw);
                if ($line === '') { $this->flush($tables, $rows, $id); continue; }
                $cells = $this->splitRow($line);
                if ($this->isTableRow($line, $cells)) {
                    $rows[] = ['line'=>$line,'cells'=>$cells,'page'=>$pageIndex+1,'line_no'=>$lineNo];
                } elseif ($rows && $this->isContinuationLine($line, $rows[count($rows)-1])) {
                    $last = count($rows)-1;
                    $rows[$last]['cells'][] = $line;
                    $rows[$last]['line'] .= ' ' . $line;
                } else {
                    $this->flush($tables, $rows, $id);
                }
            }
            $this->flush($tables, $rows, $id);
        }
        return $this->mergeContinuationTables($tables);
    }

    private function flush(array &$tables, array &$rows, int &$id): void
    {
        if (count($rows) < 2) { $rows=[]; return; }
        $max = max(array_map(fn($r)=>count($r['cells']), $rows));
        $header = [];
        foreach ($rows as $row) if (count($row['cells']) === $max && $max >= 2) { $header=$row['cells']; break; }
        $tables[]=['id'=>$id++,'pages'=>array_values(array_unique(array_column($rows,'page'))),'header'=>$header,'rows'=>$rows,'text'=>implode("\n",array_column($rows,'line'))];
        $rows=[];
    }

    private function splitRow(string $line): array
    {
        if (str_contains($line, '|')) return $this->parts(preg_split('/\|/', $line) ?: [], true);
        if (str_contains($line, "\t")) return $this->parts(preg_split('/\t+/', $line) ?: [], true);
        $parts = preg_split('/\s{2,}/u', trim($line)) ?: [];
        if (count($parts) > 1) return $this->parts($parts);
        if ($this->isAttribute($line) && preg_match('/^((?:[A-Z]\s+)?(?:No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri(?:\s+No)?|Dolap No|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Yer|Uzunluk|Tasarım Basıncı|Basınç|Basinç|Debi|Çap|Cap|Hortum Tipi|Tip|Tür|Tur|Pompa No|Ekipman No|Yakıt|Güç))\s+(.+)$/iu', $line, $m)) {
            return array_merge([$this->clean($m[1])], preg_split('/\s+/u', trim($m[2])) ?: []);
        }
        return [$line];
    }

    private function parts(array $parts, bool $preserveEmpty=false): array
    {
        $out=[];
        foreach ($parts as $p) { $v=trim((string)$p); if ($preserveEmpty || $v!=='') $out[]=$v; }
        return $out;
    }

    private function isTableRow(string $line, array $cells): bool
    {
        return $this->isAttribute($line) || $this->isControl($line) || count($cells) >= 2;
    }

    private function isContinuationLine(string $line, array $previous): bool
    {
        $key=$this->normalizeKey($previous['cells'][0]??'');
        return in_array($key,['bulundugu yer','lokasyon','konum','yer','kat'],true)
            && !preg_match('/^(?:No|Kod|Kat|Marka|Model|Seri|Dolap|Uzunluk|Basınç|Basinç|Debi|Çap|Cap|Hortum|Pompa|Yakıt|Güç)\b/iu',$line);
    }

    private function mergeContinuationTables(array $tables): array
    {
        $out=[];
        foreach ($tables as $table) {
            $i=count($out)-1;
            if ($i>=0 && $this->isContinuationTable($out[$i],$table)) {
                $out[$i]['rows']=array_merge($out[$i]['rows'],$table['rows']);
                $out[$i]['pages']=array_values(array_unique(array_merge($out[$i]['pages'],$table['pages'])));
                $out[$i]['text'].="\n".$table['text'];
            } else $out[]=$table;
        }
        return $out;
    }

    private function isContinuationTable(array $a,array $b): bool
    {
        if (min($b['pages'])-max($a['pages'])>1) return false;
        $ha=$this->normalizeKey(implode('|',$a['header']));
        $hb=$this->normalizeKey(implode('|',$b['header']));
        if ($ha!=='' && $ha===$hb) return true;
        return $this->hasEquipmentCode($a['text']) && $this->hasEquipmentCode($b['text']) && abs(count($a['header'])-count($b['header']))<=1;
    }

    private function matchTables(array $tables,string $name,string $category): array
    {
        $tokens=$this->tokens($name); $out=[];
        foreach ($tables as $table) {
            $text=mb_strtolower($table['text'],'UTF-8'); $score=0;
            foreach ($tokens as $token) if (mb_strlen($token,'UTF-8')>=3 && str_contains($text,$token)) $score+=3;
            foreach ($this->categoryWords($category) as $word) if (str_contains($text,$word)) $score++;
            if ($this->hasTechnical($table)) $score+=2;
            if ($this->hasEquipmentCode($table['text'])) $score++;
            if ($this->hasControl($table['text'])) $score++;
            if ($score>=3) $out[]=$table;
        }
        return $out;
    }

    private function isCabinetTable(array $table,string $category): bool
    {
        return $category==='yangin_dolabi' || preg_match('/\b(?:YD\s*[- ]?\s*\d+|Dolap No)\b/iu',$table['text'])===1;
    }

    private function extractCabinetComponents(array $table): array
    {
        $codeRow=null;
        foreach($table['rows'] as $row){
            $first=(string)($row['cells'][0]??'');
            if(preg_match('/(?:No\s*\/\s*Kod|Dolap No)/iu',$first) || preg_match('/\bYD\s*[- ]?\s*\d+[A-Z]?\b/iu',$row['line'])){$codeRow=$row;break;}
        }
        if(!$codeRow)return [];
        $codes=$this->extractCodes($codeRow);
        if(!$codes)return [];
        $records=[]; $n=count($codes);
        for($i=0;$i<$n;$i++)$records[$i]=['code'=>$codes[$i],'name'=>'Yangın Dolabı','location'=>null,'brand'=>null,'model'=>null,'serial_no'=>null,'properties'=>[],'control_items'=>[],'source_pages'=>$table['pages']];

        foreach($table['rows'] as $row){
            $label=$this->label($row['cells'][0]??'');
            if($label===null || preg_match('/^(?:No\s*\/\s*Kod|Dolap No)$/iu',$label))continue;
            $key=$this->normalizeKey($label);
            $values=$this->valuesForColumns($row,$n,$key);
            foreach($records as $i=>&$record){
                $value=$this->clean($values[$i]??null);
                if($value===null)continue;
                if($this->isControl($row['line'])){$code=$this->controlCode($row['line']);$record['control_items'][$code?:'control']=$value;}
                elseif(in_array($key,['kat','bulundugu yer','lokasyon','konum','yer'],true))$record['location']=$value;
                elseif(in_array($key,['marka','uretici'],true))$record['brand']=$value;
                elseif(in_array($key,['model','model no'],true))$record['model']=$value;
                elseif(in_array($key,['seri no','serino','seri numarasi'],true))$record['serial_no']=$value;
                elseif(!$this->isUnitOnly($value))$record['properties'][$key]=$value;
            } unset($record);
        }
        return $records;
    }

    private function extractCodes(array $row): array
    {
        $codes=[];
        foreach(array_slice($row['cells'],1) as $cell){
            if(preg_match_all('/\bYD\s*[- ]?\s*\d+[A-Z]?\b/iu',(string)$cell,$m))foreach($m[0] as $v)$codes[]=strtoupper(preg_replace('/\s+|-/','',$v));
            elseif(preg_match('/^\d{1,4}[A-Z]?$/u',trim((string)$cell)))$codes[]=trim((string)$cell);
        }
        return array_values(array_unique($codes));
    }

    private function extractGenericComponents(array $table,string $systemName): array
    {
        $attrs=$this->attributeRows($table); if(!$attrs)return [];
        $map=[
            'code'=>['kod','no','ekipman no','pompa no','hidrant no','cihaz no','etiket'],
            'name'=>['ekipman','ekipman adi','ekipman adı','tip','tur','tür','pompa'],
            'location'=>['kat','bulunduğu yer','bulundugu yer','lokasyon','konum','yer'],
            'brand'=>['marka','üretici','uretici'],
            'model'=>['model','model no'],
            'serial_no'=>['seri no','serino','seri numarasi','seri numarası'],
        ];
        $values=[]; foreach($map as $field=>$aliases)$values[$field]=$this->findAttribute($attrs,$aliases);
        $n=max(array_map('count',$values)); if($n===0)return [];
        $skip=array_merge(...array_values($map)); $technical=$attrs;
        foreach($skip as $skipKey)unset($technical[$this->normalizeKey($skipKey)]);
        $out=[];
        for($i=0;$i<$n;$i++){
            $item=['code'=>$this->clean($values['code'][$i]??null),'name'=>$this->clean($values['name'][$i]??null)?:($systemName?:null),'location'=>$this->clean($values['location'][$i]??null),'brand'=>$this->clean($values['brand'][$i]??null),'model'=>$this->clean($values['model'][$i]??null),'serial_no'=>$this->clean($values['serial_no'][$i]??null),'properties'=>$this->columnProperties($technical,$i),'source_pages'=>$table['pages']];
            if($item['code']!==null||$item['name']!==null||$item['location']!==null)$out[]=$item;
        }
        return $out;
    }

    private function attributeRows(array $table): array
    {
        $out=[];
        foreach($table['rows'] as $row){$label=$this->label($row['cells'][0]??'');if($label!==null&&count($row['cells'])>=2)$out[$this->normalizeKey($label)]=$row;}
        return $out;
    }

    private function findAttribute(array $attrs,array $aliases):array
    {
        foreach($aliases as $alias){$needle=$this->normalizeKey($alias);foreach($attrs as $key=>$row)if($key===$needle||str_contains($key,$needle)||str_contains($needle,$key))return $this->valuesForColumns($row,max(1,count($row['cells'])-1),$key);}
        return [];
    }

    private function columnProperties(array $attrs,int $index):array
    {
        $out=[];
        foreach($attrs as $key=>$row){$values=$this->valuesForColumns($row,max($index+1,count($row['cells'])-1),$key);if(isset($values[$index])&&$this->clean($values[$index])!==null)$out[$key]=$values[$index];}
        return $out;
    }

    private function valuesForColumns(array $row,int $expected,string $key):array
    {
        $joined=trim(implode(' ',array_slice($row['cells'],1)));
        if($joined==='')return array_fill(0,$expected,null);
        if($this->isControl($row['line'])){preg_match_all('/\b(?:UD|U|N)\b/iu',$joined,$m);return $this->pad(array_map(fn($x)=>strtoupper($x),$m[0]),$expected);}

        // Reconstruct P/U pairs without knowing the form/vendor.
        if(preg_match_all('/P\s*:\s*(.*?)\s+U\s*:\s*(.*?)(?=\s+P\s*:|$)/iu',$joined,$pairs,PREG_SET_ORDER)){
            $v=[];foreach($pairs as $pair)$v[]='P: '.trim($pair[1]).' U: '.trim($pair[2]);
            if(count($v)>=$expected)return array_slice($v,0,$expected);
        }

        if(in_array($key,['kat','bulundugu yer','lokasyon','konum','yer'],true)){
            $locations=$this->locationValues($joined,$expected);
            if(count($locations)>=$expected)return array_slice($locations,0,$expected);
        }

        // Drop measurement units which the PDF extractor may expose as a cell.
        $joined=(string)preg_replace('/^\(\s*(?:bar|m2?|kw|mm|cm|l\/dk|m3(?:\/s|\/h)?)\s*\)\s*/iu','',$joined);
        $tokens=array_values(array_filter(preg_split('/\s+/u',trim($joined))?:[],fn($x)=>$x!==''));
        if(count($tokens)===$expected)return $tokens;
        if(count($tokens)<$expected)return $this->pad($tokens,$expected);

        if(in_array($key,['kat','bulundugu yer','lokasyon','konum','yer'],true))$tokens=$this->mergeFloorTokens($tokens);
        while(count($tokens)>$expected){
            $merged=false;
            for($i=0;$i<count($tokens)-1;$i++){
                if($this->isUpperToken($tokens[$i])&&$this->isUpperToken($tokens[$i+1])){$tokens[$i].=' '.$tokens[$i+1];array_splice($tokens,$i+1,1);$merged=true;break;}
            }
            if(!$merged)break;
        }
        if(count($tokens)>$expected)$tokens=array_merge(array_slice($tokens,0,$expected-1),[implode(' ',array_slice($tokens,$expected-1))]);
        return $this->pad($tokens,$expected);
    }

    private function locationValues(string $value,int $expected):array
    {
        $value=preg_replace('/\s+/u',' ',trim($value));
        preg_match_all('/-?\d+\.\s*KAT|\b\d+\.?\s*KAT|\bZEM[Iİ]N\b|\bBODRUM\b|\bTERAS\b|\bÇATI\b|\bCATI\b/iu',(string)$value,$m);
        $values=array_values(array_filter(array_map(fn($x)=>$this->clean($x),$m[0])));
        if(count($values)>=$expected)return $values;
        if(count($values)===1&&$expected>1)return array_fill(0,$expected,$values[0]);
        return $this->mergeFloorTokens(preg_split('/\s+/u',(string)$value)?:[]);
    }

    private function mergeFloorTokens(array $tokens):array
    {
        $out=[];for($i=0;$i<count($tokens);$i++){if($i+1<count($tokens)&&preg_match('/^-?\d+\.$/u',$tokens[$i])&&mb_strtoupper($tokens[$i+1],'UTF-8')==='KAT'){$out[]=$tokens[$i].' KAT';$i++;}else$out[]=$tokens[$i];}return $out;
    }

    private function extractControls(array $table):array
    {
        if(!$this->hasControl($table['text']))return [];
        $out=[];
        foreach($table['rows'] as $row){if(!$this->isControl($row['line']))continue;$code=$this->controlCode($row['line']);$description=$this->controlDescription($row['line']);preg_match_all('/\b(?:UD|U|N)\b/iu',$row['line'],$m);$results=[];foreach($m[0] as $i=>$status)$results[(string)($i+1)]=strtoupper($status);$out[]=['control_code'=>$code,'description'=>$description,'results'=>$results,'nonconforming_count'=>count(array_filter($results,fn($v)=>$v==='UD')),'source_pages'=>$table['pages']];}
        return $out;
    }

    private function controlCode(string $line):?string{return preg_match('/^(\d+(?:\.\d+)*\.?)/u',trim($line),$m)?rtrim($m[1],'.'):null;}
    private function controlDescription(string $line):string{$x=trim((string)preg_replace('/^\d+(?:\.\d+)*\.?\s*/u','',trim($line)));return trim((string)preg_replace('/\s+(?:UD|U|N)(?:\s+(?:UD|U|N))*$/iu','',$x));}

    private function uniqueComponents(array $items):array{$out=[];$seen=[];foreach($items as $item){$key=mb_strtolower(implode('|',[$item['code']??'',$item['name']??'',$item['location']??'',$item['brand']??'',$item['model']??'',$item['serial_no']??'']),'UTF-8');if(isset($seen[$key]))continue;$seen[$key]=1;$out[]=$item;}return $out;}
    private function uniqueControls(array $items):array{$out=[];$seen=[];foreach($items as $item){$key=mb_strtolower(implode('|',[$item['system_name']??'',$item['category']??'',$item['control_code']??'',$item['description']??'']),'UTF-8');if(isset($seen[$key]))continue;$seen[$key]=1;$out[]=$item;}return $out;}
    private function uniqueSystems(array $items):array{$out=[];$seen=[];foreach($items as $item){$key=mb_strtolower(($item['category']??'').'|'.($item['name']??''),'UTF-8');if(isset($seen[$key]))continue;$seen[$key]=1;$out[]=$item;}return $out;}
    private function uiTable(array $t):array{return ['table_id'=>$t['id'],'pages'=>$t['pages'],'headers'=>$t['header'],'rows'=>array_map(fn($r)=>$r['cells'],$t['rows']),'raw_text'=>$t['text']];}
    private function relevant(array $t):bool{return $this->hasTechnical($t)||$this->hasEquipmentCode($t['text'])||$this->hasControl($t['text']);}
    private function hasTechnical(array $t):bool{return preg_match('/\b(?:Marka|Model|Seri|Uzunluk|Basınç|Basinç|Debi|Çap|Cap|Hortum|Yakıt|Güç|Pompa No|Dolap No|Kat)\b/iu',$t['text'])===1;}
    private function hasEquipmentCode(string $text):bool{return preg_match('/\b(?:YD\s*[- ]?\s*\d+[A-Z]?|\d{1,4}[A-Z]?)\b/iu',$text)===1;}
    private function hasControl(string $text):bool{return preg_match('/\b\d+\.\d+\s+.*\b(?:UD|U|N)\b/iu',$text)===1;}
    private function isControl(string $line):bool{return preg_match('/^\d+(?:\.\d+)+\.?\s+/u',trim($line))===1;}
    private function isAttribute(string $line):bool{return preg_match('/^(?:[A-Z]\s+)?(?:No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri(?:\s+No)?|Dolap No|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Yer|Uzunluk|Tasarım Basıncı|Basınç|Basinç|Debi|Çap|Cap|Hortum Tipi|Tip|Tür|Tur|Pompa No|Ekipman No|Yakıt|Güç|Devreye girme)\b/iu',trim($line))===1;}
    private function label(string $value):?string{$value=trim($value);if($value==='')return null;if(preg_match('/^((?:[A-Z]\s+)?(?:No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri(?:\s+No)?|Dolap No|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Yer|Uzunluk|Tasarım Basıncı|Basınç|Basinç|Debi|Çap|Cap|Hortum Tipi|Tip|Tür|Tur|Pompa No|Ekipman No|Yakıt|Güç|Devreye girme[^:]*))\b/iu',$value,$m))return trim($m[1]);return $value;}
    private function normalizeKey(string $value):string{$value=mb_strtolower(trim($value),'UTF-8');$value=strtr($value,['ı'=>'i','İ'=>'i','ş'=>'s','Ş'=>'s','ğ'=>'g','Ğ'=>'g','ü'=>'u','Ü'=>'u','ö'=>'o','Ö'=>'o','ç'=>'c','Ç'=>'c']);$value=preg_replace('/\s+/u',' ',$value);return trim((string)$value);}
    private function clean(?string $value):?string{if($value===null)return null;$value=trim(preg_replace('/\s+/u',' ',$value));return $value===''||$value==='-'?null:$value;}
    private function isUnitOnly(string $value):bool{return preg_match('/^\(\s*(?:bar|m2?|kw|mm|cm)\s*\)$/iu',trim($value))===1;}
    private function pad(array $values,int $expected):array{$values=array_values($values);while(count($values)<$expected)$values[]=null;return array_slice($values,0,$expected);}
    private function isUpperToken(string $token):bool{$letters=preg_replace('/[^\p{L}]/u','',$token);return $letters!==''&&mb_strtoupper($letters,'UTF-8')===$letters&&mb_strlen($letters,'UTF-8')>=2;}
    private function tokens(string $text):array{return array_values(array_filter(preg_split('/\s+/u',$this->normalizeKey($text))?:[],fn($x)=>mb_strlen($x,'UTF-8')>=3));}
    private function category(string $category,string $name):string{$v=$this->normalizeKey($category.' '.$name);if(str_contains($v,'dolab')||str_contains($v,'hortum'))return 'yangin_dolabi';if(str_contains($v,'pompa'))return 'yangin_pompasi';if(str_contains($v,'sprink')||str_contains($v,'yagmurlama'))return 'sprinkler';if(str_contains($v,'hidrant'))return 'hidrant';if(str_contains($v,'depo'))return 'su_deposu';if(str_contains($v,'gaz'))return 'gazli_sondurme';if(str_contains($v,'belge')||str_contains($v,'kayit'))return 'diger';return $category!==''?$category:'diger';}
    private function categoryWords(string $category):array{return match($category){'yangin_dolabi'=>['yangin dolabi','hortum','dolap'],'yangin_pompasi'=>['yangin pompasi','pompa'],'sprinkler'=>['sprink','yagmurlama','puskurtme'],'hidrant'=>['hidrant'],'su_deposu'=>['su deposu','depo'],'gazli_sondurme'=>['gazli','sondurme'],default=>[]};}
}
