<?php

namespace App\Services\Ai\PkTakip;

/**
 * Bölüm alt ajanı (yerel model, tesisat): raporun bilgi / sonuç / kuruluş bölümlerinden (Docling, PkDocumentSections)
 * rapor bilgilerini, genel sonucu ve muayene kuruluşunu okur; ana okumanın aynı alanlarının üzerine yazar. Ana okumanın
 * diğer alanları (notlar, bina gibi tür anahtarları) kalır. Şema alanları JSON sözleşmesinden birebir.
 */
class PkHeaderPass
{
    private const KEYS = ['report_no', 'company_title', 'address', 'report_date', 'control_date', 'validity_date'];

    private const PROMPT = <<<'PROMPT'
Sana bir tesisat periyodik kontrol raporunun bilgi, sonuç ve kuruluş bölümleri verilecek. Bu bölümlerden aşağıdakileri raporda yazdığı gibi oku; yoksa null.
- report_no: "Rapor No" / "Rapor Numarası" başlığının değeri. Sayfa köşesindeki form / doküman kodu (örn. "PR-013/FR.065/REV.00") tek başına rapor numarası değildir. Başlıklar bir satırda, değerler alttaki satırda olabilir: değer, başlığın hizasındakidir.
- company_title: kontrolü talep eden / raporu isteyen kuruluş (müşteri). Raporu düzenleyen muayene firması müşteri değildir.
- address: kontrol adresi. report_date: rapor tarihi. control_date: kontrolün / muayenenin yapıldığı tarih. validity_date: bir sonraki kontrol tarihi.
- overall_result: text = sonuç ve kanaat cümlesi, yazdığı gibi; status: kullanıma uygunsa "uygun", uygun değilse ("eksikler giderilmeden kullanılması uygun değildir" gibi) "uygun_degil", kontrol yapılamadıysa "uygulanamiyor"; net değilse null.
- inspection_body: raporu düzenleyen muayene kuruluşunun adı, adresi, telefonu, e-postası, web adresi ve personeli (kontrol eden / onaylayan); boş alanlar null.
PROMPT;

    public function __construct(private PkGeminiReportClient $schema)
    {
    }

    // Ajanın girdisi: kısa raporda bütün metin; uzunda bilgi / sonuç / kuruluş bölümleri + kusur bölümü (bazı formatlarda
    // "SONUÇ:" kusur bölümünün içinde yazılır; firma adı başka başlığın altına düşebilir — OKCO PK25, 24 Eki). Bölüm yoksa bütün metin.
    public static function input(array $parts, string $structuredText): string
    {
        if (mb_strlen($structuredText) <= 15000) {
            return $structuredText;
        }

        return implode("\n\n", array_filter([$parts['header'] ?? null, $parts['findings'] ?? null])) ?: $structuredText;
    }

    // $rules: türün ek kuralları (örn. asansörün sonuç sınıfları), talimatın sonuna eklenir.
    public function apply(array $semantic, string $sectionsText, ?string $provider = null, string $rules = ''): array
    {
        $contract = $this->schema->responseSchema()['properties']['extracted_data']['properties'];
        $text = ['type' => ['string', 'null']];
        $schema = ['type' => 'object', 'properties' => [
            ...array_fill_keys(self::KEYS, $text),
            'overall_result' => $contract['overall_result'],
            'inspection_body' => $contract['inspection_body'],
        ], 'required' => [...self::KEYS, 'overall_result', 'inspection_body']];

        $found = PkLocalRetry::client($provider, fn (array $r) => blank($r['report_no'] ?? null) && blank($r['control_date'] ?? null) && blank($r['overall_result']['status'] ?? null))->extract(self::PROMPT . ($rules !== '' ? "
" . $rules : ''), $sectionsText, 4000, schema: $schema);

        $info = collect((array) ($semantic['extracted_data']['report_information'] ?? []))->keyBy('key');
        foreach (self::KEYS as $key) {
            if (filled($found[$key] ?? null)) {
                $info[$key] = ['key' => $key, 'value' => trim((string) $found[$key])];
            }
        }
        $semantic['extracted_data']['report_information'] = $info->values()->all();
        if (filled($found['overall_result']['status'] ?? null)) {
            $semantic['extracted_data']['overall_result'] = $found['overall_result'];
        }
        if (filled($found['inspection_body']['name'] ?? null)) {
            $semantic['extracted_data']['inspection_body'] = $found['inspection_body'];
        }

        return $semantic;
    }
}
