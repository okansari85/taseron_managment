<?php

namespace App\Services\Ai;

/**
 * Generic, firm-independent table analyzer.
 * Handles horizontal/vertical tables, repeated headers and continuation pages.
 */
class UniversalFireSuppressionTableAnalyzerV2
{
    public function analyze(array $pages, array $semantic): array
    {
        $tables = $this->mergeContinuations($this->discover($pages));
        $systems = [];
        $equipment = [];
        $controls = [];
        $used = [];

        foreach ((array) ($semantic['systems'] ?? []) as $semanticSystem) {
            if (!is_array($semanticSystem)) continue;
            $name = trim((string) ($semanticSystem['name'] ?? ''));
            $category = $this->category((string) ($semanticSystem['category'] ?? ''), $name);
            $matched = $this->match($tables, $name, $category);
            $used = array_merge($used, array_column($matched, 'id'));
            $matrix = ['codes' => [], 'locations' => []];
            $components = [];
            $systemControls = [];
            $uiTables = [];

            foreach ($matched as $table) {
                $uiTables[] = $this->uiTable($table);
                $systemControls = array_merge($systemControls, $this->controls($table));
                if ($category === 'yangin_dolabi') {
                    $matrix = $this->mergeMatrix($matrix, $this->cabinetMatrix($table));
                } else {
                    $components = array_merge($components, $this->components($table));
                }
            }

            if ($category === 'yangin_dolabi') {
                foreach ($matrix['codes'] as $i => $code) {
                    $components[] = [
                        'code' => $code,
                        'name' => 'Yangın Dolabı',
                        'location' => $matrix['locations'][$i] ?? null,
                        'brand' => null, 'model' => null, 'serial_no' => null,
                        'properties' => [], 'source_pages' => $this->pages($matched),
                    ];
                }
            }

            $components = $this->uniqueComponents($components);
            $systemControls = $this->uniqueControls($systemControls);
            foreach ($components as $c) {
                $equipment[] = [
                    'code' => $c['code'] ?? $c['name'] ?? null,
                    'category' => $category,
                    'system_name' => $name ?: null,
                    'system_category' => $category,
                    'location_note' => $c['location'] ?? null,
                    'brand' => $c['brand'] ?? null,
                    'model' => $c['model'] ?? null,
                    'serial_no' => $c['serial_no'] ?? null,
                    'result' => null,
                    'note' => null,
                    'control_items' => [],
                    'properties' => $c['properties'] ?? [],
                    'source_pages' => $c['source_pages'] ?? [],
                ];
            }
            foreach ($systemControls as $c) {
                $c['system_name'] = $name ?: null;
                $c['category'] = $category;
                $controls[] = $c;
            }

            $systems[] = [
                'name' => $name ?: null,
                'category' => $category,
                'control_count' => count($systemControls),
                'nonconforming_count' => count(array_filter($systemControls, fn($x) => ($x['nonconforming_count'] ?? 0) > 0)),
                'components' => $components,
                'equipment_matrix' => $matrix,
                'tables' => $uiTables,
                'control_items' => $systemControls,
            ];
        }

        return [
            'systems' => $this->uniqueSystems($systems),
            'equipment' => $this->uniqueComponents($equipment),
            'control_matrix' => $this->uniqueControls($controls),
            'tables' => array_values(array_map(fn($t) => $this->uiTable($t), array_filter($tables, fn($t) => !in_array($t['id'], $used, true) && $this->relevant($t)))),
            'analyzer' => [
                'version' => '2.0.0',
                'table_count' => count($tables),
                'equipment_count' => count($equipment),
                'control_count' => count($controls),
            ],
        ];
    }

    private function discover(array $pages): array
    {
        $out = []; $id = 1;
        foreach (array_values($pages) as $p => $page) {
            $rows = [];
            foreach (preg_split('/\R/u', (string)$page) ?: [] as $lineNo => $raw) {
                $line = trim((string)$raw);
                if ($line === '') { $this->flush($out, $rows, $id, $p + 1); continue; }
                $cells = $this->split($line);
                if ($this->isAttr($line) || $this->isControl($line) || count($cells) >= 2) {
                    $rows[] = ['line'=>$line,'cells'=>$cells,'page'=>$p+1,'line_no'=>$lineNo];
                    continue;
                }
                // Multi-line cell, especially "Bulunduğu Yer" in real reports.
                if ($rows && $this->isContinuationValue($line, $rows[count($rows)-1])) {
                    $last = count($rows)-1;
                    $rows[$last]['cells'][] = $line;
                    $rows[$last]['line'] .= ' ' . $line;
                } else {
                    $this->flush($out, $rows, $id, $p + 1);
                }
            }
            $this->flush($out, $rows, $id, $p + 1);
        }
        return $out;
    }

    private function flush(array &$out, array &$rows, int &$id, int $page): void
    {
        if (count($rows) >= 2) {
            $max = max(array_map(fn($r)=>count($r['cells']),$rows));
            $header = [];
            foreach ($rows as $r) if (count($r['cells']) === $max && $max >= 2) { $header=$r['cells']; break; }
            $out[]=['id'=>$id++,'pages'=>array_values(array_unique(array_column($rows,'page'))),'header'=>$header,'rows'=>$rows,'text'=>implode("\n",array_column($rows,'line'))];
        }
        $rows=[];
    }

    private function split(string $line): array
    {
        if (str_contains($line,'|')) return $this->parts(preg_split('/\|/u',$line) ?: []);
        if (str_contains($line,"\t")) return $this->parts(preg_split('/\t+/u',$line) ?: []);
        $x = preg_split('/\s{2,}/u',$line) ?: [];
        if (count($x)>1) return $this->parts($x);
        if ($this->isAttr($line) && preg_match('/^((?:[A-Z]\s+)?(?:No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri(?: No)?|Dolap No|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Yer|Uzunluk|Tasarım Basıncı|Basınç|Basinç|Debi|Çap|Cap|Hortum Tipi|Tip|Tür|Tur|Pompa No|Ekipman No|Soru\s*\/\s*Kriter))\s+(.+)$/iu',$line,$m)) return array_merge([trim($m[1])],preg_split('/\s+/u',trim($m[2])) ?: []);
        return [$line];
    }

    private function mergeContinuations(array $tables): array
    {
        $out=[];
        foreach($tables as $t){
            $i=count($out)-1;
            if($i>=0 && $this->continuation($out[$i],$t)){
                $out[$i]['rows']=array_merge($out[$i]['rows'],$t['rows']);
                $out[$i]['pages']=array_values(array_unique(array_merge($out[$i]['pages'],$t['pages'])));
                $out[$i]['text'].="\n".$t['text'];
                if(count($out[$i]['header'])<count($t['header']))$out[$i]['header']=$t['header'];
            } else $out[]=$t;
        }
        return $out;
    }

    private function continuation(array $a,array $b): bool
    {
        if(min($b['pages'])-max($a['pages'])>1)return false;
        $ah=$this->signature($a['header']);$bh=$this->signature($b['header']);
        if($ah!==''&&$ah===$bh)return true;
        $aCodes=$this->hasAnyCode($a['text']);$bCodes=$this->hasAnyCode($b['text']);
        return $aCodes&&$bCodes&&abs(count($a['header'])-count($b['header']))<=1;
    }

    private function match(array $tables,string $name,string $category): array
    {
        $tokens=$this->tokens($name);$out=[];
        foreach($tables as $t){
            $text=mb_strtolower($t['text'],'UTF-8');$score=0;
            foreach($tokens as $token)if(mb_strlen($token)>=3&&str_contains($text,$token))$score+=2;
            foreach($this->categoryWords($category) as $w)if(str_contains($text,$w))$score++;
            if($this->hasTechnical($t))$score+=2;if($this->hasControl($t['text']))$score++;if($this->hasAnyCode($t['text']))$score++;
            if($score>=3)$out[]=$t;
        }
        return $out;
    }

    private function cabinetMatrix(array $t): array
    {
        $codes=[];$attrs=$this->attrs($t);
        foreach($t['rows'] as $r)foreach($r['cells'] as $cell)if(preg_match_all('/\bYD\s*[- ]?\s*\d+[A-Z]?\b/iu',$cell,$m))foreach($m[0] as $v)$codes[]=strtoupper(preg_replace('/\s+|-/', '',$v));
        $codes=array_values(array_unique($codes));
        if(!$codes){
            $vals=$this->find($attrs,['dolap no','kod','no','soru / kriter']);
            foreach($vals as $v)if(preg_match('/^\d{1,4}[A-Z]?$/u',trim($v)))$codes[]=$v;
            $codes=array_values(array_unique($codes));
        }
        $loc=$this->find($attrs,['kat','bulunduğu yer','bulundugu yer','lokasyon','konum','yer']);
        $locations=[];
        for($i=0;$i<count($codes);$i++)$locations[]=$this->clean($loc[$i]??(count($loc)===1?$loc[0]:null));
        return ['codes'=>$codes,'locations'=>$locations];
    }

    private function components(array $t): array
    {
        $a=$this->attrs($t);$map=[
            'code'=>['kod','no','ekipman no','pompa no','hidrant no','cihaz no','etiket'],
            'name'=>['ekipman','ekipman adi','ekipman adı','tip','tur','tür','pompa'],
            'location'=>['kat','bulunduğu yer','bulundugu yer','lokasyon','konum','yer'],
            'brand'=>['marka','üretici','uretici'],'model'=>['model','model no'],
            'serial_no'=>['seri no','serino','seri numarasi','seri numarası']
        ];
        $v=[];foreach($map as $k=>$aliases)$v[$k]=$this->find($a,$aliases);
        $n=max(array_map('count',$v));if($n===0)return [];
        $skip=array_merge(...array_values($map));$technical=$a;foreach($skip as $s)unset($technical[$this->normalizeKey($s)]);
        $out=[];
        for($i=0;$i<$n;$i++){
            $x=['code'=>$this->clean($v['code'][$i]??null),'name'=>$this->clean($v['name'][$i]??null),'location'=>$this->clean($v['location'][$i]??null),'brand'=>$this->clean($v['brand'][$i]??null),'model'=>$this->clean($v['model'][$i]??null),'serial_no'=>$this->clean($v['serial_no'][$i]??null),'properties'=>$this->column($technical,$i),'source_pages'=>$t['pages']];
            if($x['code']||$x['name']||$x['location'])$out[]=$x;
        }
        return $out;
    }

    private function controls(array $t): array
    {
        $out=[];foreach($t['rows'] as $r){if(!$this->isControl($r['line']))continue;$label=$r['cells'][0]??$r['line'];preg_match('/^(\d+(?:\.\d+)*\.?)/',$label,$m);$res=[];foreach(array_slice($r['cells'],1) as $i=>$cell){$s=$this->status($cell);if($s)$res[(string)($i+1)]=$s;}preg_match_all('/\b(?:U|UD|N)\b/iu',$r['line'],$tokens);$count=array_filter($res,fn($x)=>$x==='UD');$out[]=['control_code'=>$m[1]??null,'description'=>trim(preg_replace('/^\d+(?:\.\d+)*\.?\s*/u','',$label)),'results'=>$res,'nonconforming_count'=>count($count),'source_pages'=>$t['pages']];}return $out;
    }

    private function attrs(array $t): array {$o=[];foreach($t['rows'] as $r){$label=$this->label($r['cells'][0]??'');if($label!==null&&count($r['cells'])>=2)$o[$this->normalizeKey($label)]=array_slice($r['cells'],1);}return $o;}
    private function find(array $a,array $aliases): array {foreach($aliases as $alias){$k=$this->normalizeKey($alias);foreach($a as $ak=>$v)if($ak===$k||str_contains($ak,$k)||str_contains($k,$ak))return array_values($v);}return [];}
    private function column(array $a,int $i): array {$o=[];foreach($a as $k=>$v)if(array_key_exists($i,$v))$o[$k]=$v[$i];return $o;}
    private function mergeMatrix(array $a,array $b):array{foreach($b['codes'] as $i=>$c){$p=array_search($c,$a['codes'],true);if($p===false){$a['codes'][]=$c;$a['locations'][]=$b['locations'][$i]??null;}elseif(!$a['locations'][$p])$a['locations'][$p]=$b['locations'][$i]??null;}return $a;}
    private function pages(array $t):array{return array_values(array_unique(array_merge(...array_map(fn($x)=>$x['pages'],$t?:[['pages'=>[]]]))));}
    private function uiTable(array $t):array{return ['table_id'=>$t['id'],'pages'=>$t['pages'],'headers'=>$t['header'],'rows'=>array_map(fn($r)=>$r['cells'],$t['rows']),'raw_text'=>$t['text']];}
    private function uniqueComponents(array $a):array{$o=[];$s=[];foreach($a as $x){$k=mb_strtolower(implode('|',[$x['code']??'',$x['name']??'',$x['location']??'',$x['brand']??'',$x['model']??'',$x['serial_no']??'']),'UTF-8');if(isset($s[$k]))continue;$s[$k]=1;$o[]=$x;}return $o;}
    private function uniqueControls(array $a):array{$o=[];$s=[];foreach($a as $x){$k=mb_strtolower(($x['system_name']??'').'|'.($x['control_code']??'').'|'.($x['description']??''),'UTF-8');if(isset($s[$k]))continue;$s[$k]=1;$o[]=$x;}return $o;}
    private function uniqueSystems(array $a):array{$o=[];$s=[];foreach($a as $x){$k=mb_strtolower(($x['name']??'').'|'.($x['category']??''),'UTF-8');if(isset($s[$k]))continue;$s[$k]=1;$o[]=$x;}return $o;}
    private function parts(array $a):array{return array_values(array_filter(array_map('trim',$a),fn($x)=>$x!==''));}
    private function label(string $v):?string{return preg_match('/^(?:[A-Z]\s+)?(?:No\s*\/\s*Kod|Kod|Kat|Marka|Model|Seri(?: No)?|Dolap No|Bulunduğu Yer|Bulundugu Yer|Lokasyon|Konum|Yer|Uzunluk|Tasarım Basıncı|Basınç|Basinç|Debi|Çap|Cap|Hortum Tipi|Tip|Tür|Tur|Pompa No|Ekipman No|Soru\s*\/\s*Kriter)\b/iu',trim($v))?$v:null;}
    private function isAttr(string $v):bool{return $this->label($v)!==null;}
    private function isControl(string $v):bool{return preg_match('/(?:^|\s)\d+(?:\.\d+)+\.?\s+.+\b(?:U|UD|N)\b/iu',trim($v))===1;}
    private function hasControl(string $v):bool{return preg_match('/\b(?:U|UD|N)\b/iu',$v)===1;}
    private function hasAnyCode(string $v):bool{return preg_match('/\b(?:YD\s*[- ]?\s*\d+|H\s*[- ]?\s*\d+|P\s*[- ]?\s*\d+)\b/iu',$v)===1||preg_match('/\bDolap\s*No\b/iu',$v)===1;}
    private function hasTechnical(array $t):bool{return preg_match('/marka|model|seri|basınç|basinc|uzunluk|debi|çap|cap|kat|lokasyon|konum|hortum|tip|tür|tur/iu',implode(' ',$t['header']))===1;}
    private function relevant(array $t):bool{return $this->hasAnyCode($t['text'])||$this->hasControl($t['text'])||$this->hasTechnical($t);}
    private function isContinuationValue(string $line,array $prev):bool{return preg_match('/^[A-ZÇĞİÖŞÜ0-9]/u',$line)===1&&$this->label($prev['cells'][0]??'')!==null;}
    private function status(string $v):?string{$v=mb_strtoupper(trim($v),'UTF-8');return in_array($v,['U','UD','N'],true)?$v:null;}
    private function clean(?string $v):?string{if($v===null)return null;$v=trim(preg_replace('/\s+/u',' ',$v));return $v===''||$v==='-'?null:$v;}
    private function normalizeKey(string $v):string{$v=mb_strtolower(trim($v),'UTF-8');$v=strtr($v,['ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c']);return trim(preg_replace('/[^a-z0-9]+/u',' ',$v)??$v);}
    private function signature(array $h):string{return implode('|',array_map(fn($v)=>$this->normalizeKey($v),$h));}
    private function tokens(string $v):array{return array_values(array_unique(array_filter(explode(' ',$this->normalizeKey($v)),fn($x)=>mb_strlen($x)>=3)));}
    private function category(string $c,string $n):string{$v=$this->normalizeKey($c.' '.$n);return match(true){str_contains($v,'dolap')=>'yangin_dolabi',str_contains($v,'pompa')=>'yangin_pompasi',str_contains($v,'hidrant')=>'hidrant',str_contains($v,'sprinkler')||str_contains($v,'yagmurlama')=>'sprinkler',str_contains($v,'su depo')=>'su_deposu',str_contains($v,'su alma')||str_contains($v,'su verme')=>'su_alma_verme',str_contains($v,'boru')||str_contains($v,'kollektor')=>'sabit_boru_tesisati',str_contains($v,'gazli')||str_contains($v,'fm200')||str_contains($v,'co2')=>'gazli_sondurme',default=>$c!==''?$c:'diger'};}
    private function categoryWords(string $c):array{return match($c){'yangin_dolabi'=>['dolap','hortum'],'yangin_pompasi'=>['pompa','jokey','debi'],'hidrant'=>['hidrant'],'sprinkler'=>['sprinkler','yagmurlama'],'su_deposu'=>['depo'],'su_alma_verme'=>['su alma','su verme','itfaiye'],'sabit_boru_tesisati'=>['boru','kollektor','vana'],'gazli_sondurme'=>['gazli','fm200','co2'],default=>[]};}
}
