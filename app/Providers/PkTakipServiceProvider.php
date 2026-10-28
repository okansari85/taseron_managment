<?php

namespace App\Providers;

use App\Services\Ai\CamelotPdfTableExtractor;
use App\Services\Ai\FireSuppressionOverallResultFallback;
use App\Services\Ai\FireSuppressionResultMerger;
use App\Services\Ai\FireSuppressionStandardResultExtractor;
use App\Services\Ai\FireSuppressionUnifiedNormalizer;
use App\Services\Ai\PkTakip\PkCachedCamelot;
use App\Services\Ai\PkTakip\PkElectricalReportReader;
use App\Services\Ai\PkTakip\PkReportTableReader;
use App\Services\Ai\TemplateDrivenFireSuppressionExtractor;
use App\Services\Ai\TemplateDrivenFireSuppressionMatrixExtractor;
use Illuminate\Support\ServiceProvider;

// PKTakip okuyucularına önbellekli Camelot (PkCachedCamelot): normalizer'ın Camelot çağrıları ve tablo etiketleri tek
// okumayı paylaşır. Yalnız PKTakip sınıflarına bağlanır; Taşeron'un aynı servisleri bildiği gibi çalışır.
class PkTakipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PkCachedCamelot::class);
        $this->app->when(PkElectricalReportReader::class)->needs(CamelotPdfTableExtractor::class)->give(PkCachedCamelot::class);
        $this->app->when(PkReportTableReader::class)->needs(FireSuppressionUnifiedNormalizer::class)->give(function ($app) {
            $camelot = $app->make(PkCachedCamelot::class);

            return new FireSuppressionUnifiedNormalizer(
                new TemplateDrivenFireSuppressionMatrixExtractor($app->makeWith(TemplateDrivenFireSuppressionExtractor::class, ['camelot' => $camelot]), $camelot),
                new FireSuppressionStandardResultExtractor($camelot),
                $app->make(FireSuppressionResultMerger::class),
                new FireSuppressionOverallResultFallback($camelot),
                $camelot,
            );
        });
    }
}
