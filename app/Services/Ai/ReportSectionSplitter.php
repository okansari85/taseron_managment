<?php

namespace App\Services\Ai;

/**
 * Bir raporu SAYFA değil BÖLÜM birimine göre parçalar.
 *
 * Sayfa güvenilir bir birim değil: aynı sayfada bir bölümün kuyruğu ile
 * sonraki bölümün başı bir arada olabilir (gerçek raporda sayfa 12: sprinkler
 * listesi + "8. KUSUR AÇIKLAMALARI / NOTLAR" + "9. SONUÇ VE KANAAT" hepsi
 * aynı sayfada), ve bir bölüm hiç başlık tekrarlamadan birden fazla sayfaya
 * yayılabilir (sayfa 7-10: sadece tablo satırları devam ediyor, yeni başlık
 * yok). Bu sınıf tüm sayfaların METİN AKIŞINI satır satır tarar, tanınan bir
 * bölüm başlığı gördüğünde yeni bir bölüm başlatır; tanınmayan bir başlık
 * (örn. "5.5. DİZEL POMPALARA AİT YAKIT TÜKETİM BİLGİLERİ" — "1. GENEL
 * BİLGİLER"in bir alt başlığı) mevcut bölümü DEĞİŞTİRMEZ, çünkü aynı ana
 * bölümün devamı olması, hiç ilgisi olmayan yeni bir bölüm olmasından çok
 * daha olasıdır.
 */
class ReportSectionSplitter
{
    // needle => [topic, type]. "topic" aynı geniş type (örn. equipment_list)
    // içindeki alt-listeleri (dolap/hidrant/sprinkler) birbirinden ayırmak
    // için kullanılır — aksi halde sayfa 6-12 arasındaki dolap+hidrant+
    // sprinkler listeleri TEK bölüm sayılıp FireSuppressionEquipmentListParser
    // ilk bulduğu türe (yangin_dolabi) hepsini yanlışlıkla bağlardı.
    private const HEADINGS = [
        'genel bilgiler' => ['topic' => 'general_info', 'type' => PdfPageClassifier::GENERAL_INFO],
        // Pompa bölümleri raporlar arasında farklı şekillerde başlıklandırılıyor
        // ("2.1. YANGIN POMPALARI TESPİT VE DEĞERLENDİRMELER", "5. POMPA GRUBU
        // ETİKET BİLGİLERİ / TESPİT EDİLEN BİLGİLER", "5.1. 1 NUMARALI POMPA").
        // type=EQUIPMENT_LIST (GENERAL_INFO DEĞİL — gerçek veriyle görüldü ki
        // "1. GENEL BİLGİLER" bölümünde HİÇ ekipman olmuyor, sadece rapor
        // meta verisi var; pompa GERÇEK bir ekipman tablosu, kendi ayrı
        // bölümü olmalı). Bunun İKİ faydası var: (1) orkestratör artık
        // GENERAL_INFO/RESULT'tan gelen AI çıktısının equipment alanını hiç
        // güvenmiyor (bkz. parse() — "1. GENEL BİLGİLER"deki "Ekipman Seri
        // No" gibi tek bir meta alanı AI'ın sahte bir ekipman kaydına
        // çevirmesi, gerçek testte "YT-01" kodlu hayalet bir dolap kaydı
        // olarak ortaya çıktı), pompa artık bu güvensiz gruba KARIŞMIYOR;
        // (2) pompa metni artık genel bilgiler+sonuç ile TEK bir AI
        // çağrısında birleştirilmiyor, kendi başına gidiyor — modelin
        // dikkatini bölmeden daha güvenilir çıkarım yapması beklenir.
        'pompaları tespit ve değerlendirmeler' => ['topic' => 'equipment_list:pompa', 'type' => PdfPageClassifier::EQUIPMENT_LIST],
        'pompa grubu etiket bilgileri' => ['topic' => 'equipment_list:pompa', 'type' => PdfPageClassifier::EQUIPMENT_LIST],
        'numaralı pompa' => ['topic' => 'equipment_list:pompa', 'type' => PdfPageClassifier::EQUIPMENT_LIST],
        'muayene kriterleri ve testler' => ['topic' => 'control_criteria', 'type' => PdfPageClassifier::CONTROL_CRITERIA],
        'kontrol kriterleri ve testler' => ['topic' => 'control_criteria', 'type' => PdfPageClassifier::CONTROL_CRITERIA],
        'yangın dolabı listesi' => ['topic' => 'equipment_list:yangin_dolabi', 'type' => PdfPageClassifier::EQUIPMENT_LIST],
        'hidrant listesi' => ['topic' => 'equipment_list:hidrant', 'type' => PdfPageClassifier::EQUIPMENT_LIST],
        'sprinkler listesi' => ['topic' => 'equipment_list:sprinkler', 'type' => PdfPageClassifier::EQUIPMENT_LIST],
        'su alma/verme ağızları' => ['topic' => 'equipment_list:su_alma_verme', 'type' => PdfPageClassifier::EQUIPMENT_LIST],
        'kusur açıklamaları / notlar' => ['topic' => 'findings', 'type' => PdfPageClassifier::FINDINGS],
        'tespit ve bulgular' => ['topic' => 'findings', 'type' => PdfPageClassifier::FINDINGS],
        'bulgular / notlar' => ['topic' => 'findings', 'type' => PdfPageClassifier::FINDINGS],
        'sonuç ve kanaat' => ['topic' => 'result', 'type' => PdfPageClassifier::RESULT],
        'onay' => ['topic' => 'result', 'type' => PdfPageClassifier::RESULT],
    ];

    /**
     * @param string[] $pages
     * @return array<int, array{type: string, topic: string, text: string, pages: int[]}>
     */
    public function split(array $pages): array
    {
        $sections = [];
        $currentTopic = 'unknown';
        $currentType = PdfPageClassifier::UNKNOWN;
        $currentLines = [];
        $currentPages = [];

        $flush = function () use (&$sections, &$currentTopic, &$currentType, &$currentLines, &$currentPages): void {
            $text = trim(implode("\n", $currentLines));

            if ($text !== '') {
                $sections[] = [
                    'type' => $currentType,
                    'topic' => $currentTopic,
                    'text' => $text,
                    'pages' => array_values(array_unique($currentPages)),
                ];
            }

            $currentLines = [];
            $currentPages = [];
        };

        foreach (array_values($pages) as $pageIndex => $pageText) {
            $lines = preg_split('/\r\n|\r|\n/', $pageText) ?: [];

            foreach ($lines as $rawLine) {
                $line = trim($rawLine);

                if ($line === '') {
                    continue;
                }

                $heading = $this->headingFor($line);

                if ($heading !== null && $heading['topic'] !== $currentTopic) {
                    $flush();
                    $currentTopic = $heading['topic'];
                    $currentType = $heading['type'];
                }

                $currentLines[] = $line;
                $currentPages[] = $pageIndex + 1;
            }
        }

        $flush();

        return $sections;
    }

    // Bir satırın "N.NN. BAŞLIK METNİ" biçiminde GERÇEK bir bölüm başlığı
    // olup olmadığını kontrol eder. Numara öneki SADECE rakam ve nokta
    // içermelidir (harf İÇERMEMELİ) — "6.D.9. YANGIN DOLABI..." gibi bir
    // BULGU REFERANS KODU yanlışlıkla başlık sayılmasın diye (bulgu
    // kodlarında D/G/H gibi harfler rakamların arasına giriyor, gerçek
    // bölüm başlıklarında ise önek yalnızca "7.1." gibi rakam+nokta'dır).
    private function headingFor(string $line): ?array
    {
        if (! preg_match('/^(\S+)\s+(.+)$/u', $line, $m)) {
            return null;
        }

        [, $token, $rest] = $m;

        if (! preg_match('/^\d{1,3}(?:\.\d{1,3}){0,3}\.$/u', $token)) {
            return null;
        }

        $lowerRest = $this->lowerTr($rest);

        // "7. EK-1: YANGIN DOLABI - HİDRANT - SU ALMA/VERME AĞIZLARI -
        // SPRİNKLER LİSTESİ" gibi bir ŞEMSİYE başlık, içinde geçen son
        // anahtar kelimeyle (örn. "sprinkler listesi") yanlışlıkla asıl alt
        // başlıktan ÖNCE bir bölüm açar — bu satır zaten hemen ardından
        // gelen GERÇEK alt başlıkla (7.1., 7.2. ...) geçersiz kılınacağı
        // için, burada hiç bölüm açmadan mevcut bölümün devamı sayılır.
        if (str_starts_with($lowerRest, 'ek-')) {
            return null;
        }

        foreach (self::HEADINGS as $needle => $heading) {
            if (str_contains($lowerRest, $needle)) {
                return $heading;
            }
        }

        return null;
    }

    private function lowerTr(string $value): string
    {
        $value = str_replace(['İ', 'I'], ['i', 'ı'], $value);

        return mb_strtolower($value, 'UTF-8');
    }
}
