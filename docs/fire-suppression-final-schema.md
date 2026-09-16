# Yangın Söndürme Rapor Analizi — Final JSON Şeması

Kullanıcı ile birlikte kararlaştırılan hedef çıktı şeması. Bu dosya, farklı denetim
firmalarının (OKCO, NETA, ...) farklı rapor formatlarından bu ORTAK şemaya normalize
edilmiş final JSON'u tanımlar. Amaç: 100 farklı firmanın raporunda da aynı şekle
normalize etmek.

## Geliştirme iş akışı (önemli — tekrar tekrar hatırlanmalı)

- Gemini'ye her rapor için TEK SEFER istek atılır (template discovery + findings).
- Ham Gemini cevabı fikstür olarak kaydedilir: `storage/app/private/fire-suppression-gemini-fixtures/<uuid>.json` (+ aynı isimde `.pdf`).
  Şekil: `{fixture_id, provider, model, original_file_name, created_at, pdf_path, semantic: {template, extracted_data}}`.
- Sonraki tüm geliştirme/test bu fikstür + PDF üzerinden yapılır — Gemini'ye tekrar istek ATILMAZ.
- Şu an elde 2 fikstür var:
  - `0463f5e9-47e4-4ab0-9a29-8913c6a4a941` → OKCO raporu (`template_type: SULU_YANGIN_SONDURME_TESISATI_PERIYODIK_KONTROL_RAPORU`), matris tipi (equipment × kriter tablosu).
  - `8e734b42-d0ec-4bd9-82e4-f3d0c22c9f5a` → NETA raporu (`template_type: neta_fire_inspection_v1`), anlatı/bulgu tipi + kısmi matris.

## Final şema

```json
{
  "report_information": {
    "inspected_company": {
      "title": "...", "address": "...", "sgk_detsis_no": "...",
      "report_no": "...", "report_date": "...", "control_date": "...", "validity_date": "..."
    },
    "inspection_company": { "name": "...", "address": "...", "phone": "...", "accreditation_no": "..." },
    "inspector": { "name": "...", "title": "...", "diploma_no": "...", "ministry_registration_no": "..." }
  },
  "facility_or_project_information": {
    "_comment": "Gemini template'inin field key'lerine göre dinamik, rapor formatına göre değişir",
    "manufacture_year": "...", "water_source": "..."
  },
  "systems": [
    {
      "system_name": "Yangın Pompa Bölmesi",
      "has_equipment_matrix": false,
      "control_items": [
        { "code": "5.1", "criterion": "Proje varlığı ve onayı", "result": "UD", "result_normalized": "uygun_degil", "source_pages": [1] }
      ],
      "equipment": [
        {
          "code": "1", "name": "Yangın Pompası",
          "properties": { "Marka": "MAS", "Seri No": "A1205075" },
          "control_results": []
        }
      ]
    },
    {
      "system_name": "Yangın Dolapları ve Hortum Sistemlerinin Kontrolü",
      "has_equipment_matrix": true,
      "control_items": [ { "code": "5.38", "criterion": "Hortumda TSE standardı varlığı" } ],
      "equipment": [
        {
          "code": "YD1", "name": "Yangın Dolabı",
          "properties": { "Kat": "2. KAT", "Marka": "Türkoğlu" },
          "control_results": [
            { "code": "5.38", "result": "U", "result_normalized": "uygun" },
            { "code": "5.47", "result": "UD", "result_normalized": "uygun_degil" }
          ]
        }
      ]
    }
  ],
  "findings": [
    { "id": "finding-1", "system_name": "...", "description": "...", "source_pages": [4], "affected_equipments": [] }
  ],
  "overall_result": { "status": "uygun_degil", "text": "..." }
}
```

### Notlar
- `result_normalized` (`uygun` / `uygun_degil` / `unknown`): ham `result` metni (U/UD, U.D/U.Y/G gibi
  rapora göre değişen kelimeler) korunur, AYRICA normalize edilmiş bir durum alanı eklenir ki
  sayım/özet tarafı rapor formatından bağımsız çalışabilsin. Mevcut `applyEquipmentCompliance()`
  mantığı buraya taşınacak/genelleştirilecek.
- `has_equipment_matrix`: Gemini'nin `control_matrix.present` bilgisinden gelir — equipment'ta
  `control_results` mi yoksa sadece `properties` mi olacağını belirler.
- **"YD10-20" gibi aralık/gruplanmış ekipman kodları TEK TEK ekipmana açılmalı** (YD10, YD11 ... YD20
  — 11 ayrı equipment kaydı). Şu an `TemplateDrivenFireSuppressionExtractor::expandEquipmentCodes()`
  bunu YAPMIYOR, tüm aralığı tek garip koda yazıyor — bilinen hata, düzeltilecek.
- `findings`: Gemini'nin ham çıktısı aynen korunur (system_name + description + source_pages),
  sadece boş bir `affected_equipments` yer tutucu eklenir; doldurma mantığı (bulgu metninden
  ekipman kodu çıkarma) SONRAKİ bir aşama — şimdilik öncelik değil.

## Öncelik sırası (kullanıcı tarafından belirlendi)
1. Rapor bilgileri (`report_information`, denetlenen + denetleyen firma + müfettiş).
2. Tesisat genel bilgileri (`facility_or_project_information`, Gemini template'ine göre dinamik).
3. Kontrol kriterleri (`control_items`, kod + kriter metni + sonuç; sonuç kelimeleri rapora göre
   dinamik, deseni Gemini ham çıktısında/template'inde geliyor).
4. Ekipmanlar — **eksiksiz ve hatasız** gelmeli (aralık açma dahil). ŞU AN EN ÖNEMLİ 2 ÖNCELİK
   (equipment doğruluğu + control_items doğruluğu) budur.
5. Findings — Gemini çıktısı aynen korunur, `affected_equipments` en son eklenecek.

## Bilinen hatalar — DÜZELTİLDİ (2026-09-16)
1. `FireSuppressionResultMerger::expandCodePatterns()` gerçek çapalı PCRE desenlerini tanımıyordu
   → her sistemde `control_items` sıfıra iniyordu. Düzeltme: `merge()` artık Camelot'un zaten doğru
   bulduğu `control_items`'ı taban alıyor, Gemini sadece somut (pattern olmayan) bir kod verdiğinde
   üzerine yazıyor. Ayrıca `GeminiTemplateDiscoveryClient.php` şemasında `extracted_data.fire_systems`
   hiç istenmiyor (sadece `findings` zorunlu) — yani "Gemini kriter sahibidir" dalı zaten hep boş.
2. `TemplateDrivenFireSuppressionExtractor::expandEquipmentCodes()` gruplanmış/aralıklı ekipman
   kodlarını ("62-63-...-74" tam liste, "10-20" 2 sayılı aralık) tek tek ekipmana açmıyordu.
   Düzeltildi: tam liste olduğu gibi bölünüyor, 2 sayılı tire ifadesi aralık olarak genişletiliyor.
3. `TemplateDrivenFireSuppressionExtractor::matchControlCode()` kod hücresini TAM eşleşme (`^..$`)
   ile arıyordu; NETA gibi raporlarda kod hücresi "A.1." (sonda nokta) gibi trailing noktalama
   içerebiliyor, bu da hiç eşleşmiyordu → o raporun TÜM sistemlerinde control_items sıfırdı.
   Düzeltildi: eşleşmeden sonra opsiyonel `.`/`:`/`)` kabul ediliyor. **Not:** bu tür küçük yazım
   farkları Gemini pattern hatası SAYILMAZ, matcher'ı esnetmek doğru yaklaşım (bkz. proje hafızası
   `feedback_pattern_vs_gemini_bug`) — 100 farklı firma raporu için tek tek örnek beklenemez.
4. "vertical_key_value" orientation'lı equipment (örn. NETA'nın "Pompa Grubu"su — her pompa kendi
   küçük lattice tablosunda, `table_structure.left_column`/`right_column` alanlarındaki eşleşmeyen
   label setleriyle ayrılmış) hiç işlenmiyordu (`extractHorizontalEquipment` sadece tek paylaşılan
   tablo + equipment-kodu-sütunları şeklini destekliyor). Yeni bir fonksiyon eklendi:
   `extractVerticalKeyValueEquipment()` — her equipment_identity deseni ile sayfa sırasına göre
   bulunan lattice bloklarını eşleştiriyor, yanlış-pozitif blokları (örn. "Marka" ile başlayan
   alakasız bir dizel yakıt tablosu) etiket-sayısı eşiğiyle eliyor.

Doğrulama: her iki fikstür de (`0463f5e9...` OKCO, `8e734b42...` NETA) uçtan uca
`FireSuppressionUnifiedNormalizer::normalize()` ile çalıştırılıp kontrol edildi — OKCO çıktısı
değişmedi (byte-byte aynı), NETA'da 7 sistemin hepsinde `control_items` doldu, Dolap=127,
Hidrant=33, Pompa=4 (1,2,3,JOKEY) doğru equipment kodlarıyla çıktı.

## Ölçüt: "100 farklı firma" hedefi
Şema alanları rapor formatından BAĞIMSIZ olmalı — format-özel bilgi sadece Gemini'nin ürettiği
template'teki `label_patterns`/`control_code_patterns`/`result_patterns` üzerinden gelir, final
şemanın kendi alan adları (key'ler) sabit kalır. Yeni bir firma formatı eklemek şemaya yeni alan
eklemek değil, o firmanın PDF'i için doğru bir Gemini template'i üretilmesi (ve extractor'ın o
template'i doğru yorumlaması) meselesi olmalı.
