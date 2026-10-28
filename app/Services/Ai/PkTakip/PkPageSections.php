<?php

namespace App\Services\Ai\PkTakip;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Raporu insan gibi okuyarak bölümlere ayırır: her sayfa ayrı istekle, satırları numaralı; ajan yeni bölümün başladığı satırların
 * numarasını ve bölümün türünü söyler, kod raporu bu numaralardan keser (metinde arama / eşleştirme yok). Docling'in başlık
 * öğelerine dayanmaz: form raporlarında başlıkların çoğu tablo hücresi ya da düz satır ("NOTLAR:" PK25-0092-004), Docling
 * sayfa başlığını bölüm sanıyor. Talimatta rapordan örnek cümle yok: küçük model örnekleri çıktıya kopyalıyordu (25 Eki).
 */
class PkPageSections
{
    public const LABELS = ['rapor_bilgisi', 'teknik_ozellikler', 'kontrol_listesi', 'ekipman_listesi', 'olcum', 'kusur_notlar', 'sonuc', 'onay', 'diger'];

    // Satırın ajana giden uzunluğu: bölüm başını tanımak için satırın başı yeter (uzun tablo satırları sayfayı şişiriyordu).
    private const LINE_CHARS = 160;

    private const PROMPT = <<<'PROMPT'
Sana bir periyodik kontrol raporunun BİR sayfası verilecek; satırlar numaralı ("12| …"). Sayfayı bir insan gibi BAŞTAN SONUNA kadar oku ve sayfadaki BÜTÜN başlık satırlarını yaz. Başlık satırı, altındaki satırların ne olduğunu söyleyen satırdır; bir tablonun içinde ya da düz satır olabilir. Bir sayfada birden çok bölüm olabilir: kusurlar, sonuç ve onay bölümleri çoğu zaman sayfanın sonundadır; ilk başlığı bulunca durma, son satıra kadar devam et.
HER başlık satırı için:
- line: başlık satırının numarası.
- label: bölümün türü, anlamına göre:
  rapor_bilgisi: rapor no, tarihler, müşteri / firma, adres gibi raporun kimlik bilgileri.
  teknik_ozellikler: tesisin / sistemin genel ve teknik bilgileri, kullanılan ölçüm aletleri, mevzuat, kontrol metodu.
  kontrol_listesi: maddeler hâlinde kontrol soruları / kriterleri ve sonuçları.
  ekipman_listesi: ekipmanların tek tek listesi ya da tek bir ekipmanın bilgileri.
  olcum: ölçüm / test değerleri.
  kusur_notlar: muayenede bulunan kusurlar, uygunsuzluklar, eksiklikler, notlar, açıklamalar.
  sonuc: raporun sonucu / kanaati.
  onay: muayene personeli, onaylayan, imza bilgileri.
  diger: bunların hiçbiri (lejant, sayfa üst / alt bilgisi gibi).
Sayfada hiç başlık satırı yoksa (önceki sayfanın bölümü sürüyorsa) boş liste.
PROMPT;

    /**
     * Bölümler, rapordaki sırayla: türü, başladığı sayfa / satır ve metni (başlık satırı dahil). İlk bölüm başından önceki satırlar
     * "rapor_bilgisi"; bir sayfanın ilk bölüm başından önceki satırları önceki bölümün devamıdır.
     *
     * @param list<string> $pages Docling sayfaları (yapısı korunmuş metin)
     * @return list<array{label: string, page: int, line: int, text: string}>
     */
    public function read(array $pages, ?string $provider = null): array
    {
        $sections = [['label' => 'rapor_bilgisi', 'page' => 1, 'line' => 0, 'text' => '']];
        foreach (array_values($pages) as $index => $page) {
            $lines = array_values(array_filter(explode("\n", (string) $page), fn ($line) => trim($line) !== ''));
            $starts = $lines ? $this->startsFor($lines, $provider) : [];
            foreach ($lines as $number => $line) {
                // Aynı türden üst üste gelen başlıklar tek bölüm (ajan bütün başlıkları listeler; birleştirme kodun işi).
                if (isset($starts[$number + 1]) && $starts[$number + 1] !== $sections[count($sections) - 1]['label']) {
                    $sections[] = ['label' => $starts[$number + 1], 'page' => $index + 1, 'line' => $number + 1, 'text' => ''];
                }
                $sections[count($sections) - 1]['text'] .= $line . "\n";
            }
        }

        return array_values(array_filter($sections, fn ($section) => trim($section['text']) !== ''));
    }

    /**
     * Akışın girdisi: okuma modeliyle (config pktakip.local.reader_url) çıkarılan haritadan alt ajanların bölümleri. Harita PDF
     * başına bir kez çıkarılır, Docling önbelleğinin yanında saklanır (#18'de 12 sayfa ~165 sn). Okuma modeli tanımlı değilse ya
     * da sunucu cevap vermezse boş: çağıran Docling'in bölümleriyle sürer.
     *
     * @return array{findings?: string, header?: string, facility?: string}
     */
    public function parts(array $pages): array
    {
        if (blank(config('pktakip.local.reader_url'))) {
            return [];
        }
        $cache = 'pk-docling-cache/' . hash('sha256', json_encode(array_values($pages)) . self::PROMPT) . '.sections.json';
        $map = Storage::disk('local')->exists($cache) ? (array) json_decode((string) Storage::disk('local')->get($cache), true) : null;
        if ($map === null) {
            try {
                $map = $this->read($pages, 'local');
            } catch (\Throwable $exception) {
                Log::warning('Bölüm haritası çıkarılamadı (okuma modeli); Docling bölümleri kullanılıyor.', ['error' => $exception->getMessage()]);

                return [];
            }
            Storage::disk('local')->put($cache, json_encode($map, JSON_UNESCAPED_UNICODE));
        }
        $text = fn (array $labels) => collect($map)->filter(fn ($section) => in_array($section['label'] ?? null, $labels, true))->pluck('text')->implode("\n") ?: null;

        return array_filter(['findings' => $text(['kusur_notlar']), 'header' => $text(['rapor_bilgisi', 'sonuc', 'onay']), 'facility' => $text(['teknik_ozellikler'])]);
    }

    /**
     * Docling bölümlerinin (PkDocumentSections::texts) üstüne haritanın bölümleri; ikinci değer bulgu doğrulamasının yedeği:
     * harita bölümünde kusur bulunamazsa Docling'in kusur bölümü, o da yoksa kısa raporda bütün metin (uzun rapor küçük modelin
     * penceresine sığmıyor: #18 58 bin karakter).
     *
     * @return array{0: array, 1: ?string}
     */
    public static function merge(array $parts, array $pageParts, string $structuredText): array
    {
        $fallback = isset($pageParts['findings']) && isset($parts['findings']) && $parts['findings'] !== $pageParts['findings']
            ? $parts['findings']
            : (mb_strlen($structuredText) <= 15000 ? $structuredText : null);

        return [[...$parts, ...$pageParts], $fallback];
    }

    /** @return array<int, string> satır numarası => bölüm türü */
    protected function startsFor(array $lines, ?string $provider): array
    {
        $numbered = collect($lines)->map(fn ($line, $i) => ($i + 1) . '| ' . mb_substr($line, 0, self::LINE_CHARS))->implode("\n");
        // Okuma modeli tanımlıysa o (genel amaçlı model anlamada daha iyi: 25 Eki, 7 raporda kusur 7/7, NuExtract3 4/7), değilse
        // yerel sunucunun modeli.
        $client = filled(config('pktakip.local.reader_url'))
            ? app(PkLocalReportClient::class)->using(config('pktakip.local.reader_url'), config('pktakip.local.reader_model'))
            : PkAiProvider::client($provider);
        $found = PkLocalRetry::once('local', fn () => $client->extract(self::PROMPT, $numbered, 1500, schema: ['type' => 'object', 'properties' => [
            'starts' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'object', 'properties' => [
                'line' => ['type' => 'integer', 'minimum' => 1, 'maximum' => count($lines)],
                'label' => ['type' => 'string', 'enum' => self::LABELS],
            ], 'required' => ['line', 'label']]],
        ], 'required' => ['starts']]), fn () => false);

        return collect((array) ($found['starts'] ?? []))
            ->filter(fn ($start) => is_array($start) && ($start['line'] ?? 0) >= 1 && ($start['line'] ?? 0) <= count($lines))
            ->mapWithKeys(fn ($start) => [(int) $start['line'] => (string) $start['label']])
            ->all();
    }
}
