<?php

namespace App\Services\Ai;

/**
 * Single-request orchestration for fire-suppression reports.
 * Deterministic extraction is authoritative; AI is fallback only.
 */
class FireSuppressionSingleRequestReportParser
{
    public function __construct(
        private FireSuppressionReportParser $baseParser,
        private ReportSectionSplitter $splitter,
        private FireSuppressionEquipmentListParser $equipmentListParser,
        private FireSuppressionPumpListParser $pumpListParser,
        private FireSuppressionGeneralInfoParser $generalInfoParser,
        private FireSuppressionDeterministicDataParser $deterministicParser,
    ) {
    }

    public function parse(array $pages): array
    {
        $sections = $this->splitter->split($pages);
        $aiTexts = [];
        $equipmentRecords = [];
        $meta = $this->emptyDraft();

        foreach ($sections as $section) {
            $type = $section['type'];
            $text = $section['text'];

            if ($type === PdfPageClassifier::UNKNOWN && mb_strlen($text) < 300) continue;

            if ($section['topic'] === 'equipment_list:pompa') {
                $records = $this->pumpListParser->parse($text);
                if ($records !== []) { $equipmentRecords = [...$equipmentRecords, ...$records]; continue; }
                $aiTexts[] = $text; continue;
            }

            if ($type === PdfPageClassifier::EQUIPMENT_LIST) {
                $records = $this->equipmentListParser->parse($text);
                if ($records !== []) { $equipmentRecords = [...$equipmentRecords, ...$records]; continue; }
                $aiTexts[] = $text; continue;
            }

            if ($type === PdfPageClassifier::GENERAL_INFO) {
                $resolved = $this->generalInfoParser->parseGeneralInfo($text);
                $meta = $this->fillMissingMeta($meta, $resolved);
                if (! $this->generalInfoParser->isGeneralInfoComplete($resolved)) $aiTexts[] = $text;
                continue;
            }

            if ($type === PdfPageClassifier::RESULT) {
                $result = $this->generalInfoParser->parseOverallResult($text);
                if ($result !== null) $meta['overall_result'] ??= $result;
                else $aiTexts[] = $text;
                continue;
            }

            $aiTexts[] = $text;
        }

        // IMPORTANT: finding/control extraction must happen BEFORE the AI skip
        // decision. Finding that an equipment table exists is not sufficient.
        $deterministic = $this->deterministicParser->parse($pages, $equipmentRecords);
        $equipmentRecords = $deterministic['equipment'];

        // A matrix gives us every equipment x control-item x U/UD relation.
        // Findings may legitimately be empty when the report has no findings,
        // so matrix presence is the reliable signal that control data is complete.
        $hasDeterministicControlData = ($deterministic['matrix_count'] ?? 0) > 0;

        $aiDraft = $this->emptyDraft();
        if (! $hasDeterministicControlData && $aiTexts !== []) {
            // Fallback only. Keep one request so the old per-page NIM latency
            // does not multiply across the report.
            $aiDraft = $this->baseParser->parse([
                implode("\n\n--- RAPOR BÖLÜMÜ ---\n\n", $aiTexts),
            ]);
        }

        $aiDraft['equipment'] = $this->filterMetadataEquipment(
            is_array($aiDraft['equipment'] ?? null) ? $aiDraft['equipment'] : [],
            $equipmentRecords
        );

        $draft = $this->merge($meta, $aiDraft);
        $draft['equipment'] = $this->mergeEquipment($draft['equipment'], $equipmentRecords);

        // If AI fallback supplied equipment, apply the same deterministic
        // matrix/finding data to those records too. This is cheap and keeps
        // deterministic data authoritative even on non-standard reports.
        $finalDeterministic = $this->deterministicParser->parse($pages, $draft['equipment']);
        $draft['equipment'] = $finalDeterministic['equipment'];
        $draft['findings'] = $this->mergeFindings($draft['findings'] ?? [], $finalDeterministic['findings'] ?? []);

        return $draft;
    }

    private function emptyDraft(): array
    {
        return ['control_date'=>null,'next_control_date'=>null,'overall_result'=>null,'company_name'=>null,'covered_categories'=>[],'equipment'=>[],'findings'=>[]];
    }

    private function fillMissingMeta(array $target, array $source): array
    {
        foreach (['control_date','next_control_date','company_name'] as $key) {
            if (($target[$key]??null)===null && ($source[$key]??null)!==null) $target[$key]=$source[$key];
        }
        return $target;
    }

    private function merge(array $primary, array $secondary): array
    {
        foreach (['control_date','next_control_date','overall_result','company_name'] as $key) {
            if (($primary[$key]??null)===null && ($secondary[$key]??null)!==null) $primary[$key]=$secondary[$key];
        }
        $primary['covered_categories']=array_values(array_unique([...(array)$primary['covered_categories'], ...(array)$secondary['covered_categories']]));
        $primary['equipment']=$this->mergeEquipment($primary['equipment']??[],$secondary['equipment']??[]);
        $primary['findings']=$this->mergeFindings($primary['findings']??[],$secondary['findings']??[]);
        return $primary;
    }

    private function mergeEquipment(array $primary,array $secondary):array
    {
        $index=[];
        foreach($primary as $i=>$item){$key=$this->equipmentKey($item);if($key!==null)$index[$key]=$i;}
        foreach($secondary as $item){
            $key=$this->equipmentKey($item);
            if($key!==null && isset($index[$key])){
                $i=$index[$key];
                foreach(['category','location_note','brand','model','serial_no','result','note'] as $field){
                    if(($primary[$i][$field]??null)===null && ($item[$field]??null)!==null)$primary[$i][$field]=$item[$field];
                }
                if(($item['control_items']??[])!==[])$primary[$i]['control_items']=$item['control_items'];
            }else{
                $primary[]=$item;if($key!==null)$index[$key]=array_key_last($primary);
            }
        }
        return array_values($primary);
    }

    private function mergeFindings(array $primary,array $secondary):array
    {
        foreach($secondary as $finding){
            $key=($finding['control_item']??'').'|'.($finding['description']??'');$exists=false;
            foreach($primary as $existing){
                if(($existing['control_item']??'').'|'.($existing['description']??'')===$key){$exists=true;break;}
            }
            if(!$exists)$primary[]=$finding;
        }
        return $primary;
    }

    private function equipmentKey(array $item):?string
    {
        $code=trim((string)($item['code']??''));if($code==='')return null;
        $category=mb_strtolower(trim((string)($item['category']??'')),'UTF-8');
        return $category.'|'.mb_strtolower($code,'UTF-8');
    }

    private function filterMetadataEquipment(array $equipment,array $deterministic):array
    {
        $knownCodes=[];
        foreach($deterministic as $item){$code=$this->normalizeCode($item['code']??null);if($code!==null)$knownCodes[$code]=true;}
        return array_values(array_filter($equipment,function(array $item)use($knownCodes):bool{
            $code=$this->normalizeCode($item['code']??null);if($code===null)return false;
            if(preg_match('/^yt[-_ ]?\d+$/iu',$code)&&!isset($knownCodes[$code]))return false;
            return true;
        }));
    }

    private function normalizeCode(mixed $value):?string
    {
        if($value===null)return null;$value=mb_strtolower(trim((string)$value),'UTF-8');return $value===''?null:preg_replace('/\s+/','',$value);
    }
}
