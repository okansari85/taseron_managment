<?php

namespace App\Services\Ai;

/** Deterministic control-matrix and finding extraction, independent of AI. */
class FireSuppressionDeterministicDataParser
{
    public function parse(array $pages, array $equipment): array
    {
        $matrices = array_map(fn(string $p) => $this->matrix($p), $pages);
        $findingsByPage = array_map(fn(string $p) => $this->findings($p), $pages);
        $categories = array_map(fn(string $p) => $this->category($p), $pages);
        $equipment = $this->applyMatrix($equipment, $matrices, $categories);
        [$equipment, $findings] = $this->applyFindings($equipment, array_merge(...$findingsByPage));

        return [
            'equipment' => $equipment,
            'findings' => $findings,
            'matrix_count' => array_sum(array_map('count', $matrices)),
            'finding_count' => array_sum(array_map('count', $findingsByPage)),
            'matrix_pages' => count(array_filter($matrices, fn(array $v) => $v !== [])),
            'finding_pages' => count(array_filter($findingsByPage, fn(array $v) => $v !== [])),
        ];
    }

    private function matrix(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), fn($v) => $v !== ''));
        $out = []; $codes = [];
        foreach ($lines as $line) {
            if (preg_match('/No\s*\/?\s*Kod\b\s*(.+)$/iu', $line, $m)) {
                $codes = $this->split($m[1]);
                if (count($codes) >= 2) foreach ($codes as $code) $out[$code] ??= [];
                continue;
            }
            if ($codes === [] || !preg_match('/^(\d+\.\d+)\s+(.+)$/u', $line, $m)) continue;
            $tokens = $this->split($m[2]); $count = count($codes);
            if (count($tokens) < $count) continue;
            $statuses = array_slice($tokens, -$count);
            if (!$this->statusRow($statuses)) continue;
            $title = trim(implode(' ', array_slice($tokens, 0, count($tokens) - $count)));
            if ($title === '') continue;
            foreach ($codes as $i => $code) {
                $status = $this->status($statuses[$i] ?? null);
                if ($status !== null) $out[$code][] = ['code'=>$m[1], 'title'=>$title, 'status'=>$status, 'description'=>null];
            }
        }
        return array_filter($out, fn($v) => $v !== []);
    }

    private function findings(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: []; $out = []; $current = null;
        foreach ($lines as $raw) {
            $line = trim($raw); if ($line === '') continue;
            if (preg_match('/^(\d+\.\d+(?:\s*-\s*\d+\.\d+)?)\)\s*(.+)$/u', $line, $m)
                || preg_match('/^(\d+\.[A-ZÇĞİÖŞÜ]\.\d+)\.\s*(.+)$/u', $line, $m)) {
                if ($current !== null) $out[] = $current;
                $current = ['control_item'=>$m[1], 'description'=>trim($m[2])]; continue;
            }
            if (($out !== [] || $current !== null) && preg_match('/^\d+\.\s+[A-ZÇĞİÖŞÜ]/u', $line)) break;
            if ($current !== null) {
                if (preg_match('/[.!?]\s*$/u', $current['description'])) {
                    $out[] = $current; $current = ['control_item'=>null, 'description'=>$line];
                } else $current['description'] .= ' '.$line;
            }
        }
        if ($current !== null) $out[] = $current;
        return $out;
    }

    private function applyMatrix(array $equipment, array $matrices, array $categories): array
    {
        $merged=[]; $display=[]; $categoryByCode=[];
        foreach ($matrices as $page=>$matrix) foreach ($matrix as $code=>$items) {
            $key=$this->key($code); $merged[$key]=$this->mergeItems($merged[$key]??[], $items);
            $display[$key]??=$code; $categoryByCode[$key]??=$categories[$page]??null;
        }
        if ($merged === []) return $equipment;
        $index=[];
        foreach ($equipment as $i=>$item) if (($item['code']??null)!==null) $index[$this->key($item['code'])]=$i;
        foreach ($merged as $key=>$items) {
            if (isset($index[$key])) {
                $equipment[$index[$key]]['control_items']=$items;
                if (empty($equipment[$index[$key]]['category']) && $categoryByCode[$key]!==null) $equipment[$index[$key]]['category']=$categoryByCode[$key];
            } else {
                $equipment[]=['code'=>$display[$key],'category'=>$categoryByCode[$key]??'diger','location_note'=>null,'brand'=>null,'model'=>null,'serial_no'=>null,'result'=>null,'note'=>null,'control_items'=>$items,'is_uncertain'=>false];
            }
        }
        foreach ($equipment as $i=>$item) if (!empty($item['control_items'])) {
            $bad=false; foreach ($item['control_items'] as $ci) if (($ci['status']??null)==='uygun_degil') {$bad=true;break;}
            $equipment[$i]['result']=$bad?'uygun_degil':'uygun';
        }
        return array_values($equipment);
    }

    private function applyFindings(array $equipment, array $lines): array
    {
        $codes=[]; foreach ($equipment as $eq) foreach ($eq['control_items']??[] as $ci) if (($ci['code']??null)!==null) $codes[$this->key($ci['code'])]=true;
        $byCode=[]; $general=[];
        foreach ($lines as $line) {
            $raw=$line['control_item']??null; $code=$this->extractCode($raw); $key=$code!==null?$this->key($code):null;
            if ($key!==null && isset($codes[$key])) $byCode[$key]=isset($byCode[$key])?$byCode[$key].' '.$line['description']:$line['description'];
            else $general[]=['category'=>null,'control_item'=>$raw,'description'=>$line['description'],'scope'=>'unknown','area_note'=>null,'is_uncertain'=>false];
        }
        foreach ($equipment as $i=>$eq) {
            $bad=[];
            foreach ($eq['control_items']??[] as $j=>$ci) {
                $key=isset($ci['code'])?$this->key($ci['code']):'';
                if (isset($byCode[$key])) {
                    $equipment[$i]['control_items'][$j]['description']=$byCode[$key];
                    if (($ci['status']??null)==='uygun_degil') $bad[]=$byCode[$key];
                }
            }
            if ($bad!==[]) $equipment[$i]['note']=implode(' ',array_values(array_unique($bad)));
        }
        return [$equipment,$general];
    }

    private function category(string $text): ?string
    {
        $s=$this->lower($text); $map=['yangin_dolabi'=>['dolab','dolap'],'hidrant'=>['hidrant'],'sprinkler'=>['sprink'],'yangin_pompasi'=>['pompa'],'su_deposu'=>['su deposu','depo hacmi'],'sabit_boru'=>['sabit boru','kolektör','kolektor','boru tesisat'],'gazli_sondurme'=>['gazlı söndürme','gazli sondurme']];
        foreach($map as $cat=>$words) foreach($words as $word) if(str_contains($s,$word)) return $cat;
        return null;
    }

    private function mergeItems(array $a,array $b): array { $r=[]; foreach([...$a,...$b] as $v){$k=$this->lower(trim((string)($v['code']??$v['title']??'')));if($k!=='')$r[$k]=$v;} return array_values($r); }
    private function split(string $v): array { return array_values(array_filter(preg_split('/\s+/u',trim($v))?:[],fn($x)=>$x!=='')); }
    private function statusRow(array $v): bool { foreach($v as $x) if(!preg_match('/^(U|UD|N|-)$/iu',$x)) return false; return true; }
    private function status(?string $v): ?string { if(!$v)return null;$v=$this->lower(trim($v));if($v==='ud'||str_contains($v,'degil')||str_contains($v,'değil')||str_contains($v,'uygunsuz'))return'uygun_degil';if($v==='u'||str_contains($v,'uygun'))return'uygun';return null; }
    private function extractCode(?string $v): ?string { if($v===null)return null;if(preg_match('/(\d+\.\d+(?:\s*-\s*\d+\.\d+)?)/u',$v,$m))return$m[1];if(preg_match('/(\d+\.[A-ZÇĞİÖŞÜ]\.\d+)/u',$v,$m))return$m[1];return null; }
    private function key(string $v): string { return $this->lower(preg_replace('/\s+/u','',trim($v))??''); }
    private function lower(string $v): string { return mb_strtolower(str_replace(['İ','I'],['i','ı'],$v),'UTF-8'); }
}
