<?php

namespace App\Jobs;

use App\Domain\Tenancy\TenantContext;
use App\Models\FireSuppressionInventoryItem;
use App\Models\LocationBusinessEntity;
use App\Models\Tenant;
use App\Services\Ai\FireSuppressionAiReportAnalyzer;
use App\Services\Ai\FireSuppressionAnalysisProgress;
use App\Services\Ai\PdfTextExtractor;
use App\Services\Ai\UniversalFireSuppressionTableAnalyzerV6;
use App\Services\Matching\FireSuppressionMatchingProfile;
use App\Services\Matching\MatchingEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnalyzeFireSuppressionReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout=900;
    public int $tries=1;

    public function __construct(
        private readonly string $analysisId,
        private readonly string $storedFilePath,
        private readonly string $originalFileName,
        private readonly int $locationBusinessEntityId,
        private readonly int $tenantId,
    ) {}

    public function handle(
        PdfTextExtractor $extractor,
        FireSuppressionAiReportAnalyzer $analyzer,
        UniversalFireSuppressionTableAnalyzerV6 $tableAnalyzer,
        MatchingEngine $matchingEngine,
        FireSuppressionMatchingProfile $matchingProfile,
        FireSuppressionAnalysisProgress $progress,
        TenantContext $tenantContext
    ): void {
        $tenant=Tenant::query()->findOrFail($this->tenantId);
        $tenantContext->set($tenant);
        $branch=LocationBusinessEntity::query()->findOrFail($this->locationBusinessEntityId);
        try {
            $absolutePath=Storage::disk('local')->path($this->storedFilePath);
            $file=new UploadedFile($absolutePath,$this->originalFileName,null,null,true);
            $progress->stage($this->analysisId,'extracting','PDF metni çıkarılıyor');
            $pages=$extractor->extractPages($file);
            $progress->stage($this->analysisId,'ai','Rapor tek AI isteğiyle analiz ediliyor');
            $semantic=$analyzer->analyze($pages);
            $progress->stage($this->analysisId,'tables','Rapor tabloları dinamik olarak analiz ediliyor');
            $tables=$tableAnalyzer->analyze($pages,$semantic);

            $candidateIds=[];$matchedIds=[];$matches=[];
            foreach($tables['equipment']??[] as $index=>$equipment){
                $match=$matchingEngine->match($matchingProfile,$branch,$equipment);
                $matches[$index]=$match;
                $candidateIds=array_merge($candidateIds,$match['candidate_ids']??[]);
                if(!empty($match['matched_id']))$matchedIds[]=$match['matched_id'];
            }
            $candidateIds=array_values(array_unique(array_map('intval',$candidateIds)));
            $matchedIds=array_values(array_unique(array_map('intval',$matchedIds)));
            $candidateMap=FireSuppressionInventoryItem::query()->whereIn('id',$candidateIds)->get()->keyBy('id');
            $matchedMap=FireSuppressionInventoryItem::query()->whereIn('id',$matchedIds)->get()->keyBy('id');
            $matchedInventory=[];$candidateInventory=[];$unmatched=[];
            foreach($tables['equipment']??[] as $index=>$equipment){
                $match=$matches[$index]??['status'=>'new','matched_id'=>null,'candidate_ids'=>[]];
                $tables['equipment'][$index]['match']=$match;
                if(($match['status']??'')==='exact'&&isset($match['matched_id']))$matchedInventory[]=$matchedMap->get($match['matched_id']);
                elseif(($match['status']??'')==='candidate_single'&&isset($match['candidate_ids'][0]))$matchedInventory[]=$candidateMap->get($match['candidate_ids'][0]);
                elseif(($match['status']??'')==='candidate_multiple')foreach($match['candidate_ids']??[] as $id)if($candidateMap->has($id))$candidateInventory[]=$candidateMap->get($id);
                elseif(!empty($equipment['code']))$unmatched[]=$equipment['code'];
            }
            $tables['equipment']=array_values($tables['equipment']);
            $tables['matched_inventory_items']=array_values(array_filter($matchedInventory));
            $tables['candidate_inventory_items']=array_values(array_filter($candidateInventory));
            $tables['unmatched_codes']=array_values(array_unique($unmatched));

            $report=$semantic['report']??[];
            $draft=array_merge($semantic,[
                'control_date'=>$tables['control_date']??($report['control_date']??null),
                'next_control_date'=>$tables['next_control_date']??($report['next_control_date']??null),
                'report_no'=>$tables['report_no']??($report['report_no']??null),
                'company_name'=>$tables['company_name']??($report['company_name']??null),
                'overall_result'=>$tables['overall_result']??($report['overall_result']??null),
                'covered_categories'=>$tables['covered_categories']??[],
                'systems'=>$tables['systems']??[],
                'equipment'=>$tables['equipment']??[],
                'control_matrix'=>$tables['control_matrix']??[],
                'tables'=>$tables['tables']??[],
                'matched_inventory_items'=>$tables['matched_inventory_items']??[],
                'candidate_inventory_items'=>$tables['candidate_inventory_items']??[],
                'unmatched_codes'=>$tables['unmatched_codes']??[],
                'analyzer'=>$tables['analyzer']??[],
            ]);
            $progress->completeWithResult($this->analysisId,$draft,['counts'=>[
                'systems'=>count($draft['systems']??[]),'findings'=>count($draft['findings']??[]),
                'equipment'=>count($draft['equipment']??[]),'controls'=>count($draft['control_matrix']??[]),
                'tables'=>(int)($draft['analyzer']['table_count']??0),
            ]]);
            $this->cleanup();
        } catch(Throwable $exception){
            report($exception);$progress->fail($this->analysisId,$exception->getMessage());$this->cleanup();
        }
    }

    private function cleanup():void{try{Storage::disk('local')->delete($this->storedFilePath);}catch(Throwable){}}
}
