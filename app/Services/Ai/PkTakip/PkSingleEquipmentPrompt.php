<?php

namespace App\Services\Ai\PkTakip;

use ReflectionMethod;
use RuntimeException;

/**
 * Deneme: tek ekipman raporu için kısa talimat (küçük / yerel modeller için). Mevcut PkReportAnalyzer talimatına
 * dokunmaz: JSON sözleşmesi (13. bölüm) ve katalog talimatı oradan birebir alınır, yalnızca kurallar kısaltılır ve
 * katalogdan yalnızca seçilen tür verilir. Çıktı şeması aynıdır (PkGeminiReportClient::responseSchema).
 */
class PkSingleEquipmentPrompt
{
    // Küçük / yerel modellerin sık yaptığı hatalar için ek kurallar; kısa talimat açıkken tesisat talimatlarının sonuna da eklenir.
    public const LOCAL_RULES = <<<'PROMPT'
EK KURALLAR (bunlar yukarıdaki kurallarla çelişirse bunlar geçerlidir)
- report_no, "Rapor No" / "Rapor Numarası" başlığının değeridir. Sayfa köşesindeki form / doküman kodu (örn. "PR-013/FR.065/REV.00") TEK BAŞINA rapor numarası değildir; rapor numarası değeri mutlaka bulunmalı. Numaranın kendisinde "Rev" olabilir (örn. "PK25-093-ELK-005-Rev.0"), onu kesme.
  Bazı raporlarda başlıklar bir satırda ("Rapor No | Muayene Tarihi | Rapor Tarihi ..."), değerler alttaki satırda aynı sırayla yazılır; rapor numarası "Rapor No" başlığının hizasındaki değerdir (örn. "AB/25/1234-1/007").
- Sonuç işaretleri rapordan rapora değişir ("U", "UD", "U.D", "Uygun D.", "G.D", "✔", "✘" gibi); hangisinin "uygun değil" olduğunu raporun lejantından öğren.
- Bulgular raporun kusur / uygunsuzluk açıklaması bölümünden alınır. Kontrol listesi / kriter maddeleri (sonucu ne olursa olsun) bulgu değildir.
- Her kusur maddesi ayrı bir finding'dir. Birden çok kusuru (ayrı cümleler ya da "1- ... 2- ..." gibi numaralı maddeler, ayrı ölçüm noktaları / menfezler / panolar) tek description'da birleştirme; her birini ayrı finding yap.
  Kusur bölümünde numarasız, art arda yazılmış cümlelerin HER BİRİ ayrı bir kusurdur. Örnek: "Ön far yanmıyor. Sol arka lastik aşınmış." → iki ayrı finding.
  Bir kusuru açıklayan ek bilgi (parantez içi gibi, örn. "Zincir uzamış. (Uzama oranı %3'ten fazla)") ayrı kusur değildir, aynı finding'de kalır.
- Raporda kusur yoksa findings = [].
PROMPT;

    public const ELEVATOR_RULES = <<<'PROMPT'
BULGULAR (extracted_data.findings) — ASANSÖR RAPORU, EN ÖNEMLİ KURAL
- Bulgular ayrı bir adımda sayfa sayfa okunur: bu yanıtta findings = [] yaz.
- Asansör raporunda company_title = asansörün bulunduğu binanın / işletmenin adı (asansör bilgilerindeki ADRES satırında ya da bina sorumlusu bölümünde yazan yapı / firma adı), address = asansörün adresi (örn. "ADRES: ABC GIDA A.Ş. ÖRNEK MAH. ... NO: 5" → company_title "ABC GIDA A.Ş."). Antetteki / ilk satırdaki firma muayene firmasıdır, company_title DEĞİLDİR. Raporu düzenleyen muayene firması da, raporun adına düzenlendiği belediye de müşteri değildir.
- (Bilgi) Raporun formatı firmaya göre tamamen değişir; uygunsuzluk bölümünün adı da değişir ("ASANSÖRDE TESPİT EDİLEN UYGUNSUZLUKLAR", "KUSURLAR", "TESPİTLER" gibi). Bu bölümdeki HER madde ayrı bir finding'dir; hiçbirini atlama, birleştirme ya da kısaltma.
- Bu raporlarda kontrol kriterinin kendisi bulgu olarak yazılır (örn. "1.36.2 (K) Elektrik kuvvet panosu topraklama bağlantısı yapılmalıdır."); kriter cümlesi olması bulgu olmadığını göstermez.
- description = madde numarası + maddenin TAM metni, raporda yazdığı gibi. Madde birden çok satıra bölünmüşse satırları birleştir; sayfa altı / üstü yazılarını (sayfa no, e-imza notu) metne katma.
- Madde numarası olmayan grup başlıkları (örn. "1.36. Topraklama**" — altında numaralı maddeler olan başlık) bulgu değildir.
- severity = maddenin renk / kusur kategorisi: (K) ya da Kırmızı → "Kırmızı", (S) ya da Sarı → "Sarı", (M) ya da Mavi → "Mavi". Kategori maddenin yanında değil de bölüm başlığında ("KIRMIZI KUSURLAR" gibi) veriliyorsa o bölümdeki maddelere onu yaz; hiç belirtilmemişse null.
- system_name = ekipmanın sistem adı, source_pages = gerçek sayfa, ambiguous = false. Uygunsuzluk yoksa findings = [].

GENEL SONUÇ (extracted_data.overall_result)
Asansör raporunda sonuç "UYGUN", "HAFİF KUSURLU", "KUSURLU", "GÜVENSİZ" ya da "PERİYODİK KONTROL YAPILAMADI" sınıflarından biridir. Seçili sınıf işaret kutusu ya da bir sonraki kontrol tarihinin yazıldığı satırdır (örn. "GÜVENSİZ 60 GÜN 10.12.2025" → GÜVENSİZ).
text = seçili sınıf, raporda yazdığı gibi. status: UYGUN ve HAFİF KUSURLU → "uygun"; KUSURLU ve GÜVENSİZ → "uygun_degil"; kontrol yapılamadıysa "uygulanamiyor".
validity_date = seçili sınıfın satırındaki bir sonraki kontrol tarihi.
PROMPT;

    public function __construct(private PkReportAnalyzer $analyzer)
    {
    }

    // $catalog: PeriodicEquipmentSpecCatalog::promptCatalog(); $typeSlug: raporun türü (örn. forklift).
    public function build(array $catalog, string $typeSlug): string
    {
        $type = collect((array) ($catalog['types'] ?? []))->firstWhere('slug', $typeSlug);
        if (!$type) {
            throw new RuntimeException("Katalogda '{$typeSlug}' türü yok.");
        }

        // shortcut: sözleşme ve katalog talimatı mevcut analizörün private metotlarından okunur (kopyası tutulmasın);
        // deneme kalıcı olursa bu metinler ortak bir sınıfa taşınmalı.
        $contract = '13. SON JSON SÖZLEŞMESİ' . str((string) $this->call('systemPrompt'))->after('13. SON JSON SÖZLEŞMESİ');
        $catalogPart = (string) $this->call('catalogInstruction', ['common_specs' => $catalog['common_specs'] ?? [], 'types' => [$type]]);

        $rules = $this->rules($type['name']);
        if (str_contains($typeSlug, 'asansor')) {
            // Asansör: format her yıl farklı; uygunsuzluk maddelerinin hepsi bulgu, K/S/M renk kategorisiyle.
            $rules = str($rules)->before('BULGULAR (extracted_data.findings)') . self::ELEVATOR_RULES . "\n\nSONUÇ LEJANTI" . str($rules)->after('SONUÇ LEJANTI');
        }

        return $rules . "\n\n" . $catalogPart . "\n\n" . $contract;
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(PkReportAnalyzer::class, $method))->invoke($this->analyzer, ...$args);
    }

    private function rules(string $typeName): string
    {
        return <<<PROMPT
Sen bir iş ekipmanının periyodik kontrol raporunu okuyorsun. Rapor TEK bir "{$typeName}" ekipmanı içindir. Görevin rapordaki bilgileri aşağıdaki JSON sözleşmesine birebir uyan tek bir JSON olarak yazmak. Raporda olmayan bilgiyi uydurma; yoksa null ya da boş dizi bırak.

SABİT KARARLAR
- extracted_data.report_category = "tekli_ekipman", extracted_data.extraction_mode = "single_equipment".
- template.fire_systems.systems: TEK sistem. system_name = ekipmanın raporda yazan adı (örn. "{$typeName}"). equipment_definitions içinde TEK kayıt:
  instance_structure.equipment_axis = "none", identity_field = raporda ekipmanı tanımlayan alanın adı (örn. "Ekipman No" ya da "Seri No"), identity_value = o alanın değeri;
  attributes = rapordaki etiket / tespit bilgileri tablosunun alan:değer çiftleri, raporda yazdığı gibi ({"field", "source_pattern", "value"});
  verdict = raporun sonuç / kanaat cümlesine göre ekipmanın sonucu (status, raw: cümle, basis: "explicit", evidence: cümle).
  Sistemin kendi verdict'i de aynıdır.
- template içindeki pattern alanları (label_patterns, section_heading_patterns, status_patterns vb.) bu rapor tipinde boş dizi [] olabilir.

RAPOR BİLGİLERİ (extracted_data.report_information)
report_no (rapor numarası), company_title (raporu isteyen firma), address (firma adresi), report_date (rapor yayınlanma tarihi), control_date (periyodik kontrol tarihi), validity_date (gelecek kontrol tarihi). Değerleri raporda yazdığı gibi yaz.
- company_title ve address MÜŞTERİNİNDİR: kontrolü talep eden / raporu isteyen kuruluş ("Kontrol Talep Eden", "Raporu İsteyen Firma", "Ünvanı" gibi başlıklar). Raporu düzenleyen muayene firması (antetteki firma) müşteri DEĞİLDİR; o inspection_body'ye yazılır.
- report_no, "Rapor No" / "Rapor Numarası" başlığının değeridir. Sayfa köşesindeki form / doküman kodu (örn. "PR-013/FR.065/REV.00") TEK BAŞINA rapor numarası değildir; rapor numarası değeri mutlaka bulunmalı. Numaranın kendisinde "Rev" olabilir (örn. "PK25-093-ELK-005-Rev.0"), onu kesme.
  Bazı raporlarda başlıklar bir satırda ("Rapor No | Muayene Tarihi | Rapor Tarihi ..."), değerler alttaki satırda aynı sırayla yazılır; rapor numarası "Rapor No" başlığının hizasındaki değerdir (örn. "AB/25/1234-1/007").

TEKNİK ÖZELLİKLER (extracted_data.equipment_specs) — ek kurallar
- Bir rapor alanını yalnız anlamı BİREBİR aynı olan anahtara eşle. Benzer ama farklı bilgiyi eşleme (örn. "Tekerlek Tipi" direk tipi değildir; "Çatal Kol Genişliği" çatal boyu değildir). Karşılığı yoksa yazma.
- Her anahtar en fazla bir kez.

BULGULAR (extracted_data.findings) — EN ÖNEMLİ KURAL
- Bulgu, raporun kusur / uygunsuzluk / eksiklik bölümündeki maddelerdir. Bu bölümün başlığı firmaya göre değişir: "KUSUR AÇIKLAMALARI", "UYGUNSUZLUKLAR", "TESPİT EDİLEN EKSİKLİKLER", "AÇIKLAMALAR", "NOTLAR" gibi.
- Kontrol kriterleri / kontrol listesi maddeleri (numaralı, sonunda bir sonuç işareti olan satırlar; işaretler rapora göre değişir: U, UD, U.D, G.D, ✔, ✘, "-" gibi) BULGU DEĞİLDİR. Bir kriter "uygun değil" işaretli olsa bile kriter maddesinin metnini bulgu olarak yazma; bulgu metnini kusur bölümünden al.
- Raporda ayrı bir kusur bölümü yoksa ve "uygun değil" işaretli kriterin yanında kusuru anlatan bir açıklama yazıyorsa o açıklamayı bulgu yap. Açıklama yoksa bulgu üretme.
- Her kusur maddesi ayrı bir finding'dir. Birden çok kusuru (ayrı cümleler ya da "1- ... 2- ..." gibi numaralı maddeler) tek description'da birleştirme; her birini ayrı finding yap.
  Kusur bölümünde numarasız, art arda yazılmış cümlelerin HER BİRİ ayrı bir kusurdur. Örnek: "Ön far yanmıyor. Sol arka lastik aşınmış." → iki ayrı finding: "Ön far yanmıyor." ve "Sol arka lastik aşınmış.".
  Bir kusuru açıklayan ek bilgi (parantez içi gibi, örn. "Zincir uzamış. (Uzama oranı %3'ten fazla)") ayrı kusur değildir, aynı finding'de kalır. description = maddenin metni, varsa madde numarasıyla ("1- Ön cam silecek lastiği yok."). system_name = yukarıdaki sistemin adı, source_pages = gerçek sayfa, severity = rapor önem derecesi ayırmıyorsa null, ambiguous = false.
- Raporda kusur yoksa findings = [].

GENEL SONUÇ (extracted_data.overall_result)
text = raporun sonuç ve kanaat cümlesi, yazdığı gibi. status: kullanıma uygunsa "uygun", uygun değilse "uygun_degil".

SONUÇ LEJANTI (extracted_data.result_legend)
Raporda sonuç kodlarının açıklaması varsa (örn. "U: Uygun, UD: Uygun Değil, NU: ...") her kod için {"code", "meaning"} yaz; yoksa [].

KONTROLÜ YAPAN KURULUŞ (extracted_data.inspection_body)
Raporu düzenleyen firmanın adı, adresi, telefonu, e-postası, web adresi; personnel: kontrol eden ve onaylayan kişilerin adı, mesleği, oda sicil no, yetki no. Raporda boş bırakılmış alanlar null.

TESİS BİLGİLERİ (extracted_data.facility_information): tek ekipman raporunda genelde yoktur, [] bırak.
PROMPT;
    }
}
