<?php

namespace App\Services\Ai\PkTakip;

/**
 * Yangın tesisatı (yerel model, Docling akışı) sistemleri ve ekipman tarifleri. Bu raporlarda sistemlerin çoğu kendi başlığıyla
 * değil kontrol listesi tablosundaki gruplarla ("A Belge ve Kayıt Kontrolleri", "B Yangın Pompa İstasyonu") yer alır.
 * 1) Tablo türü ajanı, tablo başına: bölüm başlığı ve ilk satırlardan türü (bilgi / ekipman / kriter / ölçüm / diğer) ve ekipman
 *    tablosunun sistemini söyler; tablo satırı okumaz.
 * 2) Kontrol listesi (satırları Camelot'tan): grupları aynen kopyalayan ajan, her grubun sistemi (yalnız başlıkla), U dışı maddeler
 *    işaretleriyle, işaretlerin anlamı; uygun değil maddesi olan grup uygunsuz. Sistemler buradan (katalogdaki her sistem tek tek
 *    sorulmaz: küçük model yokları "var" diyordu).
 * 3) Tablo haritası, sistem başına yalnız o sistemin ekipman tablolarıyla; yapıyı PkTableDefinitions bulur, satırları mevcut
 *    tablo okuyucu okur. Kurallar mevcut talimattan (4. bölüm sistem uygunluğu).
 */
class PkFireAgents
{
    public function __construct(private PkTableDefinitions $definitions)
    {
    }

    /**
     * @param array<string, list<string>> $catalog sistem adı => ekipman türleri
     * @return array<string, array> sistem adı => sözleşmedeki sistem kaydı
     */
    public function systems(string $structuredText, array $doclingTables, string $pdfPath, array $tableLabels, array $catalog, string $verdictRules, ?string $provider = null, ?string $subject = null): array
    {
        $names = array_keys($catalog);
        $heads = $this->tableHeads($structuredText);
        if (!$heads || !$names) {
            return [];
        }

        // 1) Tablo türleri: tablo başına ayrı küçük istek (bir ajan bir tablo). Hepsini tek istekte soran ajan pompa / dolap
        // tablolarını "bilgi", test değerleri ve genel bilgi tablolarını ekipman yazıyordu (#18, PK.239). Docling'in bölüm başlığı da
        // verilir ("7.1. YANGIN DOLABI LİSTESİ"), bir önceki tablonun türü de: sayfa değişince bölünen listenin devam tablosu
        // (#18 tablo 15, satırları "AY Bulunduğu Yer", "BC Dolap No") kontrol listesi sanılıp kontrol listesi ajanına satır taşıyordu.
        // Ekipman tablosunun sistemi ayrı ajan, yalnız tablonun kendisiyle: önceki tablo bilgisi sistemi de taşıyordu ("7.2. HİDRANT
        // LİSTESİ" dolap listesinin devamı sayıldı, #18).
        $headings = collect($doclingTables)->pluck('heading', 'no');
        $kinds = ['ekipman_listesi' => 'ekipman', 'tek_ekipman_bilgisi' => 'ekipman', 'kontrol_listesi' => 'kriter', 'test_olcum_degerleri' => 'olcum', 'rapor_tesis_bilgisi' => 'bilgi', 'diger' => 'diger'];
        $types = [];
        $previous = null;
        foreach ($heads as $no => $head) {
            // Tür için bölüm başlığı verilmez: Docling başlığı sık yanlış (PK.239 kontrol listesinin üstünde "4. ÖLÇÜM ALETLERİ") ve
            // başlıksız deneme üç raporda daha iyiydi (25 Eki); başlık yalnız sistem seçiminde.
            $found = ['kind' => $this->tableKind($no, $head, null, $previous, array_keys($kinds), $provider)];
            if (($kinds[$found['kind'] ?? ''] ?? null) === 'ekipman') {
                $found['system'] = PkLocalRetry::client($provider, fn () => false)->extract(
                    "Bir yangın tesisatı raporundaki ekipman tablosunun bölüm başlığı ve ilk satırları verilecek. Ekipmanın ait olduğu sistemi seç (örn. \"Dolap No\" → yangın dolapları, \"Hidrant No\" → hidrant, pompa → pompa istasyonu); bölüm başlığı sayfa başlığıysa satırlara bak. Hiçbiri değilse null.\nSİSTEMLER: " . implode(' | ', $names),
                    "### Tablo {$no}" . (is_string($headings[$no] ?? null) ? ' (bölüm başlığı: ' . $headings[$no] . ')' : '') . "\n{$head}",
                    300,
                    schema: ['type' => 'object', 'properties' => ['system' => ['type' => ['string', 'null'], 'enum' => [...$names, null]]], 'required' => ['system']]
                )['system'] ?? null;
            }
            $types['tablo_' . $no] = ['type' => $kinds[$found['kind'] ?? 'diger'] ?? 'diger', 'system' => $found['system'] ?? null];
            $previous = "Tablo {$no}, " . ($found['kind'] ?? 'diger');
        }
        $kind = fn ($no) => $types['tablo_' . $no]['type'] ?? 'diger';

        // 2) Kontrol listesi: (a) gruplar raporda yazdığı gibi — sistem listesi verilmez, küçük model listedeki her sistem için grup
        // uyduruyordu (PK25: köpüklü, gazlı, davlumbaz…); (b) her grubun sistemi yalnız başlığıyla ayrı küçük istek.
        $out = [];
        $criteria = array_values(array_filter(array_keys($heads), fn ($no) => $kind($no) === 'kriter'));
        // Kontrol listesi bulunamazsa yedek yok: "kontrol listesi mi?" sorusuna küçük model hemen her tabloya evet diyordu (25 Eki,
        // üç raporda 19/20) ve çöp gruplar çıkıyordu. Sistem boş kalır, uzman görür.
        if ($criteria) {
            $rows = $this->definitions->rows($pdfPath, $doclingTables, $criteria);
            $groups = PkLocalRetry::client($provider, fn (array $r) => empty($r['groups']))->extract(
                "Sana bir yangın tesisatı raporunun kontrol listesi tabloları (satır satır) verilecek. Maddeler gruplar halindedir; grup başlık satırı bir başlıktan ve varsa harf koddan oluşur, yanında sonuç sütunlarının başlıkları durur (örn. \"A | Belge ve Kayıt Kontrolleri | Durum\", \"Su Deposu Kontrolleri | U. | U.D. | N.U.\"). Numaralı madde (\"1 Bakım kayıtları var mı?\", \"A.1.\", \"5.2\") ve madde açıklaması grup DEĞİLDİR; yönetmelik / standart adı da grup değildir. Tablolarda YAZAN HER grup için (yazmayan grup ekleme):\n- code: grubun harfi / numarası (örn. \"A\", \"D\", \"6.B\"), raporda yazdığı gibi; yoksa null.\n- title: grup başlığı, raporda yazdığı gibi.\n- status, raw, basis, evidence: grubun sonucu; kurallar aşağıda. Grubun maddelerinden en az biri uygun değil ise uygun_degil; değerlendirilen maddelerin hepsi uygun ise uygun; işaretlerin anlamını raporun lejantından öğren (U, U.D, U.Y, NU gibi; \"uygulanamaz / uygulaması yok\" uygun değil sayılmaz).\n\n4. SİSTEM UYGUNLUĞU" . $verdictRules,
                $rows,
                4000,
                schema: ['type' => 'object', 'properties' => ['groups' => ['type' => 'array', 'maxItems' => 40, 'items' => ['type' => 'object', 'properties' => [
                    'code' => ['type' => ['string', 'null']],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => ['string', 'null'], 'enum' => ['uygun', 'uygun_degil', 'uygulanamiyor', null]],
                    'raw' => ['type' => ['string', 'null']],
                    'basis' => ['type' => 'string', 'enum' => ['explicit', 'derived', 'not_stated']],
                    'evidence' => ['type' => ['string', 'null']],
                ], 'required' => ['code', 'title', 'status', 'raw', 'basis', 'evidence']]]], 'required' => ['groups']]
            );
            // Gruplar mı, tek maddeler mi: ayrı ajan yalnız çıkan başlık listesine bakar. İki sütunlu formda ikinci sütunun maddeleri
            // ("5.20 İzleme ve Alarmlar") talimata rağmen grup yazılıyordu (PK.239).
            $entries = array_values(array_filter((array) ($groups['groups'] ?? []), fn ($group) => filled($group['title'] ?? null)));
            $keys = array_map(fn ($i) => 'g' . $i, array_keys($entries));
            $roles = $entries ? (array) PkLocalRetry::client($provider, fn () => false)->extract(
                "Bir yangın tesisatı kontrol listesinden çıkarılmış başlıklar verilecek (her satır: anahtar, varsa kod, başlık). HER satır için seç:\n- grup: birden çok kontrol maddesini toplayan bölüm başlığı (örn. \"A Belge ve Kayıt Kontrolleri\", \"Su Deposu Kontrolleri\", \"Dizel Pompa Fonksiyon Testleri\").\n- madde: tek bir kontrol maddesi / sorusu (örn. \"5.20 İzleme ve Alarmlar\", \"1 Bakım kayıtları var mı?\", \"A.1. Proje vardır.\").\n- diger: yönetmelik / standart adı, lejant, tablo ya da bölüm başlığı olup madde toplamayan satır.",
                collect($entries)->map(fn ($group, $i) => 'g' . $i . ': ' . trim(($group['code'] ?? '') . ' ' . $group['title']))->implode("\n"),
                1500,
                schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => 'string', 'enum' => ['grup', 'madde', 'diger']]), 'required' => $keys]
            ) : [];
            $groups['groups'] = collect($entries)->filter(fn ($group, $i) => ($roles['g' . $i] ?? 'grup') === 'grup')->values()->all();
            $groupLabels = collect($groups['groups'])->flatMap(fn ($group) => array_filter([$group['title'], $group['code'] ?? null]))->unique()->values()->all();
            $tableRows = collect($criteria)->mapWithKeys(fn ($no) => [$no => $this->definitions->rows($pdfPath, $doclingTables, [$no], $groupLabels)])->filter(fn ($rows) => $rows !== '');
            // Grubun sistemi başlık ve maddeleriyle (satır başındaki "[grup]" etiketi PkTableDefinitions'ın): aynı genel başlık
            // ("TESTLER VE KONTROLLER") PK25'te belge maddeleri, PK25-0092-004'te bütün gazlı sistem; yalnız başlıkla ayırt edilemiyor.
            $rowLines = $tableRows->flatMap(fn ($rows) => explode("\n", $rows));
            $groups['groups'] = collect($groups['groups'])->map(fn (array $group) => $group + [
                'system' => PkLocalRetry::client($provider, fn () => false)->extract(
                    "Bir yangın tesisatı kontrol listesindeki grubun başlığı ve maddeleri verilecek (satırlarda [grup başlığı] ile işaretli maddeler bu grubundur). Grubun karşılığı olan sistemi seç. ÖNCE BAŞLIĞA BAK: başlık bir sistemin ya da onun ekipmanının adını içeriyorsa (yağmurlama / sprinkler, pompa, su deposu, dolap, hidrant, boru, belge / kayıt gibi) o sistemi seç; maddelere bakma. Yalnız başlık genelse (hiçbir sistemi anmıyorsa) maddelerin ne kontrol ettiğine göre seç. Hiçbiri değilse null." . (filled($subject) ? "\nRAPORUN KONUSU: {$subject}" : '') . "\nSİSTEMLER: " . implode(' | ', $names),
                    // Yalnız grubun kendi parçası (etiketten sonraki "[" etiketine kadar): iki sütunlu formda satır öbür grubun maddesini de
                    // taşıyor (PK25: "[TESTLER VE KONTROLLER] 2 Bakım kayıtları… | [Sistemin Genel Kontrolleri] 20 Test ve drenaj…").
                    'GRUP: ' . trim(($group['code'] ?? '') . ' ' . $group['title']) . "\n" . $rowLines
                        ->filter(fn ($line) => str_contains($line, '[' . $group['title'] . ']'))
                        ->map(fn ($line) => strtok(substr($line, strpos($line, '[' . $group['title'] . ']') + strlen($group['title']) + 2), '['))
                        ->take(8)->implode("\n"),
                    300,
                    schema: ['type' => 'object', 'properties' => ['system' => ['type' => ['string', 'null'], 'enum' => [...$names, null]]], 'required' => ['system']]
                )['system'] ?? null,
            ])->all();
            // Uygun değil maddeler ayrı ajan: bütün grupların sonucunu tek seferde yazan ajan U.D maddeleri kaçırıyordu (#18 G.5,
            // hepsine "uygun"). Bir grubunda uygun değil madde olan sistem uygunsuz. İki adım: maddeleri işaretiyle aynen kopyala, işaretlerin
            // anlamını ayrı ajan söyler (tek ajan U.Y'yi U.D sanıyordu, #18 C.30 / F.1). Tablo başına ayrı istek: bütün tablolar tek
            // istekte uzun listeye dönüp sınırda kesiliyordu (#18 G grubuna gelmeden 60 madde).
            $marked = $tableRows->flatMap(fn ($rows, $no) => collect((array) (PkLocalRetry::client($provider, fn () => false)->extract(
                "Sana bir yangın tesisatı raporunun kontrol listesi tablosu (satır satır) verilecek. Sonucu \"U\" (uygun) OLMAYAN HER maddeyi yaz (U.D, U.Y, NU, X, - gibi; işaret ne olursa olsun); sonucu \"U\" olan maddeyi yazma. Sonuç sütunla işaretleniyorsa (grup satırında \"U. | U.D. | N.U.\" sütunları, maddede ✓ / X / Var) hücre sütun başlığıyla verilir (örn. \"U.D.: ✓\"); sonuç o sütunun başlığıdır. Hepsi \"U\" ise boş liste.\n- item: maddenin kodu / numarası (örn. \"G.5.\", \"29\").\n- group: maddenin bağlı olduğu grup: maddenin önündeki [köşeli parantez] grubun başlığıdır, onu aynen yaz (yoksa grubun harfi, örn. \"G\").\n- mark: maddenin sonucu, raporda yazdığı gibi (örn. \"U.D\"; sütunla işaretliyse sütunun başlığı, örn. \"U.D.\").",
                $rows,
                4000,
                schema: ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'maxItems' => 80, 'items' => ['type' => 'object', 'properties' => [
                    'item' => ['type' => 'string'],
                    'group' => ['type' => ['string', 'null']],
                    'mark' => ['type' => 'string'],
                ], 'required' => ['item', 'group', 'mark']]]], 'required' => ['items']]
            )['items'] ?? []))->filter(fn ($row) => is_array($row) && filled($row['mark'] ?? null))->map(fn (array $row) => $row + ['table' => $no]))->values();
            $marks = $marked->pluck('mark')->unique()->values()->all();
            $meaning = $marks ? (array) PkLocalRetry::client($provider, fn () => false)->extract(
                "Periyodik kontrol raporunda kontrol maddelerinin sonuç işaretleri verilecek. HER işaretin anlamını yaz: uygun, uygun_degil ya da uygulanamaz (uygulaması yok / kapsam dışı). Yaygın kullanım: U = uygun; U.D, UD, U.DEĞİL, X = uygun değil; U.Y, NU, N.U, UY, - = uygulanamaz. Raporun lejantı verildiyse ona uy.",
                implode("\n", $marks),
                500,
                schema: ['type' => 'object', 'properties' => array_fill_keys($marks, ['type' => 'string', 'enum' => ['uygun', 'uygun_degil', 'uygulanamaz']]), 'required' => $marks]
            ) : [];
            $bad = $marked->filter(fn ($row) => ($meaning[$row['mark']] ?? null) === 'uygun_degil')->values();
            // Grubu boş kalan uygun değil maddeler (kodsuz grup başlıklı form, PK25 "4", "8", "29"): ayrı ajan maddenin grubunu tablodan
            // seçer; yalnız böyle madde varsa.
            $titles = collect($groups['groups'])->pluck('title')->unique()->values()->all();
            $orphans = $titles ? $bad->filter(fn ($row) => blank($row['group'] ?? null)) : collect();
            foreach ($orphans->groupBy('table', true) as $no => $rows) {
                $keys = $rows->keys()->map(fn ($i) => 'm' . $i)->all();
                $found = (array) PkLocalRetry::client($provider, fn () => false)->extract(
                    'Bir kontrol listesi tablosu ve bazı maddelerin numaraları verilecek. HER maddenin bağlı olduğu grubu seç: madde, kendi sütununda kendisinden önceki en yakın grup başlık satırının altındadır (iki sütunlu tabloda sol ve sağ sütunun grupları ayrıdır).',
                    "MADDELER:\n" . $rows->map(fn ($row, $i) => 'm' . $i . ': ' . $row['item'])->implode("\n") . "\n\nTABLO:\n" . $tableRows[$no],
                    800,
                    schema: ['type' => 'object', 'properties' => array_fill_keys($keys, ['type' => ['string', 'null'], 'enum' => [...$titles, null]]), 'required' => $keys]
                );
                $bad = $bad->map(fn ($row, $i) => isset($rows[$i]) ? ['group' => $found['m' . $i] ?? null] + $row : $row);
            }
            $bad = $bad->filter(fn ($row) => filled($row['group'] ?? null))->groupBy(fn ($row) => $this->code($row['group']));
            foreach ((array) ($groups['groups'] ?? []) as $group) {
                $name = $group['system'] ?? null;
                if (!in_array($name, $names, true)) {
                    continue;
                }
                // Aynı sisteme düşen gruplardan biri uygunsuzsa sistem uygunsuz.
                $previous = $out[$name]['verdict']['status'] ?? null;
                $items = $bad[$this->code((string) ($group['code'] ?? ''))] ?? $bad[$this->code((string) $group['title'])] ?? null;
                // Talimatın 4. bölümü (derived): uygun değil maddesi yoksa değerlendirilen maddeler uygun sayılır; grup ajanı sonucu
                // (örn. hepsi uygulanamaz) varsa o geçer. Sisteme bağlanan bulgu sonra yine uygunsuz yapar (PkFindingsPass).
                $status = $previous === 'uygun_degil' || $items ? 'uygun_degil' : ($group['status'] ?? 'uygun');
                if ($items) {
                    [$group['basis'], $group['evidence']] = ['explicit', 'Uygun değil: ' . $items->pluck('item')->implode(', ')];
                } elseif (blank($group['status'] ?? null)) {
                    [$group['basis'], $group['evidence']] = ['derived', 'Kontrol listesinde uygun değil madde yok.'];
                }
                // Başlık kodla: bulgu eşleştirmesi madde kodundan (örn. "6.D.9" → D grubu) sistemi bulur.
                $codes = [...($out[$name]['group_codes'] ?? []), ...(filled($group['code'] ?? null) ? [$this->code($group['code'])] : [])];
                $itemCodes = [...($out[$name]['item_codes'] ?? []), ...($items ? $items->map(fn ($row) => $this->code((string) $row['item']))->all() : [])];
                $out[$name] = $this->record($name, [...($out[$name]['section_heading_patterns'] ?? []), trim(($group['code'] ?? '') . ' ' . $group['title'])],['status' => $status, 'raw' => $group['raw'] ?? null, 'basis' => $status ? ($group['basis'] ?? 'derived') : 'not_stated', 'evidence' => $group['evidence'] ?? null]);
                // Bulgu eşleştirmesi için grup kodları (PkFindingsPass: "6.G.5" → G grubu → sistem) ve uygun değil madde kodları (harfsiz
                // numaralı formda "5.20" → B grubunun maddesi, PK.239).
                $out[$name]['group_codes'] = array_values(array_unique($codes));
                $out[$name]['item_codes'] = array_values(array_unique($itemCodes));
            }
        }

        // 3) Ekipman tabloları: sistem başına yalnız kendi tabloları (tablo haritası + tarif kurucu). Ekipman türü olmayan sistem (Proje
        // Bilgileri kartı) ekipman almaz.
        $equipmentTables = collect(array_keys($heads))->filter(fn ($no) => $kind($no) === 'ekipman' && !empty($catalog[$types['tablo_' . $no]['system'] ?? ''] ?? []))
            ->groupBy(fn ($no) => $types['tablo_' . $no]['system']);
        $map = [];
        foreach ($equipmentTables as $name => $nos) {
            $text = collect($nos)->map(fn ($no) => "### Tablo {$no}\n" . $heads[$no])->implode("\n\n");
            $map[$name] = $this->listTables($this->singlesAsList(app(PkTableMap::class)->map($text, [$name => $catalog[$name]], $nos->values()->all(), $tableLabels, $provider)[$name] ?? ['lists' => [], 'singles' => []], $heads, $tableLabels, $provider));
            $out[$name] ??= $this->record($name, [], ['status' => null, 'raw' => null, 'basis' => 'not_stated', 'evidence' => null]);
        }
        foreach ($this->definitions->build($pdfPath, $doclingTables, $map) as $name => $definitions) {
            $out[$name]['equipment_definitions'] = $definitions;
        }

        return $out;
    }

    // Tablonun türü (bir ajan bir tablo): bölüm başlığı, ilk satırlar ve bir önceki tablonun türüyle. Kontrol listesi ile var / yok
    // bilgisi ayrımı örnekli: "Önceki periyodik kontrol raporu var mı? | Var | Yok" soru (PK25), "Sprinkler Sistemi (Proje) | VAR" bilgi (#18).
    private function tableKind(int $no, string $head, ?string $heading, ?string $previous, array $kinds, ?string $provider): string
    {
        return (string) (PkLocalRetry::client($provider, fn () => false)->extract(
            "Sana bir yangın tesisatı periyodik kontrol raporundaki BİR tablonun bölüm başlığı ve ilk satırları verilecek (varsa bir önceki tablonun türü de). Bölüm başlığı yanıltıcı olabilir (PDF dönüştürücü bazen önceki bölümün ya da sayfanın başlığını verir): türü tablonun SATIRLARINA göre seç.\n- ekipman_listesi: aynı türden çok ekipmanı numarasıyla sıralayan liste (\"Dolap No\", \"Hidrant No\" gibi; sütunlar ya da satırlar ekipman numaraları 1, 2, 3…); satırları ekipman özellikleridir (no, marka, bulunduğu yer, ölçülen basınç, uzunluk, mesafe). Başlığında \"Soru / Kriter\" yazsa da ekipman listesidir. Önceki tablo bir ekipman listesiyse ve bu tablonun satırları aynı özelliklerle sürüyorsa (başlığı sayfa başlığı olsa da, örn. \"PERİYODİK KONTROL RAPORU\") bu tablo o listenin devamıdır: ekipman_listesi.\n- tek_ekipman_bilgisi: TEK bir ekipmanın (örn. 1 numaralı pompa, jokey pompa, su deposu) etiket / özellik tablosu (marka, tip, debi, güç gibi).\n- kontrol_listesi: satırları kontrol maddesi soruları / cümleleridir ve her maddenin bir sonucu vardır: sonuç bir sütunda yazar (U, U.D) ya da \"U. | U.D. | N.U.\" sütunlarından birine işaret konur (✓, Var / Yok). Örn. \"Önceki periyodik kontrol raporu var mı? | Var | Yok\", \"Hidrantlara erişim uygundur. | U\". Tablonun bir kısmında başka bilgiler olsa da kontrol maddeleri varsa kontrol_listesi.\n- test_olcum_degerleri: test / ölçüm sonuçları (örn. pompa test debi / basınç / devir değerleri); ekipman listesi değildir.\n- rapor_tesis_bilgisi: rapor no, tarihler, firma, kuruluş, tesis / proje / genel tesisat bilgileri (örn. bina yüksekliği, tehlike sınıfı; \"Sprinkler Sistemi (Proje) | VAR\" gibi sistemin projede / uygulamada bulunup bulunmadığı: soru değildir, U / U.D sonucu yoktur), onay / imza.\n- diger: yönetmelik / metot listesi, lejant, ölçüm aletleri listesi (alet adı, seri no, kalibrasyon tarihi) ve diğerleri.",
            ($previous ? "Önceki tablo: {$previous}\n\n" : '') . "### Tablo {$no}" . ($heading !== null ? ' (bölüm başlığı: ' . $heading . ')' : '') . "\n{$head}",
            200,
            schema: ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => $kinds]], 'required' => ['kind']]
        )['kind'] ?? 'diger');
    }

    // Docling metnindeki her tablonun başı: "### Tablo N" işaretinden sonraki ilk satırlar (kendi çıktı biçimimiz). 10 satır: listenin
    // ilk bloğunun bütün özellik satırları görünsün (#18 hidrant bloğu 7 satır; 6'da "Hidrantlar Arası Max. Mesafe" görünmüyor, tablo
    // okuyucu onu kriter sayıp 37 hidrantı uygun değil yapıyordu).
    private function tableHeads(string $structuredText, int $lines = 10): array
    {
        $heads = [];
        $current = null;
        foreach (explode("\n", $structuredText) as $line) {
            if (str_starts_with($line, '### Tablo ')) {
                $current = (int) substr($line, 10);
                $heads[$current] = [];
            } elseif ($current !== null && str_starts_with($line, '| ') && count($heads[$current]) < $lines) {
                $heads[$current][] = $line;
            } elseif (!str_starts_with($line, '| ')) {
                $current = null;
            }
        }

        return array_map(fn (array $rows) => implode("\n", $rows), array_filter($heads));
    }

    // Aynı tablodan birden çok tek ekipman ve aynı adlı liste yok: tablo sütunlu bir listedir (PK.239 "Pompa No 1 | 2 | 3 | Jokey", her
    // pompanın P / U alt sütunları 7 ayrı pompa yazılıyordu). Ayrı ajan yalnız listenin kimlik ve özellik etiketlerini seçer
    // (Camelot'un etiketlerinden); tekiller listTables ile listeye katılır.
    private function singlesAsList(array $found, array $heads, array $tableLabels, ?string $provider): array
    {
        $label = $tableLabels ? ['type' => 'string', 'enum' => $tableLabels] : ['type' => 'string'];
        $tables = collect((array) ($found['singles'] ?? []))->whereNotNull('table')->groupBy('table')->filter(fn ($same) => $same->count() > 1);
        foreach ($tables as $table => $same) {
            $name = $same->first()['equipment_name'] ?? null;
            // Aynı adlı liste bu tabloyu içeriyorsa etiketleri şüpheli (PK.239: liste kimliği "Makine Kodu", bütün pompalarda tek değer →
            // 1 pompa); yeniden seçilir. Başka tablodaki liste ise devam listesidir, listTables birleştirir.
            $index = collect((array) ($found['lists'] ?? []))->search(fn ($list) => ($list['equipment_name'] ?? null) === $name);
            if (!isset($heads[$table]) || ($index !== false && !in_array((int) $table, array_map('intval', (array) ($found['lists'][$index]['tables'] ?? [])), true))) {
                continue;
            }
            $labels = PkLocalRetry::client($provider, fn (array $r) => blank($r['identity_label'] ?? null))->extract(
                'Bir periyodik kontrol raporundaki tablo aynı türden birden çok ekipmanı yan yana / alt alta listeliyor. identity_label: her ekipmanın KENDİ numarasını / kodunu taşıyan satırın ya da sütunun etiketi (örn. "Pompa No | 1 | 2 | 3 | Jokey" → "Pompa No"); bütün ekipmanlar için aynı değeri taşıyan satır (örn. "Makine Kodu | YANGIN TESİSATI") kimlik değildir. property_labels: ekipmanların HER özellik etiketi (marka, seri no, güç, debi, basınç gibi); kimlik etiketini tekrar yazma.',
                "### Tablo {$table}\n" . $heads[$table],
                800,
                schema: ['type' => 'object', 'properties' => ['identity_label' => $label, 'property_labels' => ['type' => 'array', 'maxItems' => 20, 'items' => $label]], 'required' => ['identity_label', 'property_labels']]
            );
            if (filled($labels['identity_label'] ?? null)) {
                $list = ['identity_label' => $labels['identity_label'], 'property_labels' => array_values(array_diff((array) ($labels['property_labels'] ?? []), [$labels['identity_label']]))];
                if ($index === false) {
                    $found['lists'][] = ['equipment_name' => $name, 'tables' => [(int) $table]] + $list;
                } else {
                    $found['lists'][$index] = $list + $found['lists'][$index];
                }
            }
        }

        return $found;
    }

    // Aynı tablodan birden çok tek ekipman = o tablo bir listenin ilk sayfası: küçük model listenin başını tek tek, devamını liste
    // yazıyordu (#18 tablo 14 dolap 1-5 + tablo 15-18 liste → 5 dolap iki kez). Tablo aynı adlı listenin başına alınır.
    public function listTables(array $found): array
    {
        $lists = (array) ($found['lists'] ?? []);
        $singles = collect((array) ($found['singles'] ?? []));
        foreach ($singles->whereNotNull('table')->groupBy('table')->filter(fn ($same) => $same->count() > 1) as $table => $same) {
            $index = collect($lists)->search(fn ($list) => ($list['equipment_name'] ?? null) === ($same->first()['equipment_name'] ?? null));
            if ($index === false) {
                continue;
            }
            $lists[$index]['tables'] = collect([(int) $table, ...(array) ($lists[$index]['tables'] ?? [])])->unique()->sort()->values()->all();
            $singles = $singles->reject(fn ($single) => ($single['table'] ?? null) === $same->first()['table']);
            // Bir tablo tek ekipman türünündür: aynı tabloyu isteyen başka adlı listeler o tablodan çıkar (PK.239 pompa tablosu
            // "Pompa Kontrol Panosu" ve "Hidrofor Tankı" listelerine de verilmişti, her biri 7 sahte ekipman okudu).
            foreach ($lists as $i => $list) {
                if ($i !== $index) {
                    $lists[$i]['tables'] = array_values(array_filter((array) ($list['tables'] ?? []), fn ($no) => (int) $no !== (int) $table));
                }
            }
        }

        return ['lists' => array_values(array_filter($lists, fn ($list) => !empty($list['tables']))), 'singles' => $singles->values()->all()];
    }

    // Grup kodu karşılaştırması için (ajanın yazdığı "g." / "G" aynı grup).
    private function code(string $code): string
    {
        return mb_strtoupper(trim($code, ' .)'), 'UTF-8');
    }

    private function record(string $name, array $headings, array $verdict): array
    {
        return [
            'system_name' => $name,
            'section_heading_patterns' => $headings,
            'section_detection' => ['start_heading_patterns' => [], 'continuation_patterns' => [], 'end_detection_patterns' => []],
            'verdict' => $verdict,
            'equipment_definitions' => [],
        ];
    }
}
