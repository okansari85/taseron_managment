<?php

namespace App\Services\Ai\PkTakip;

/**
 * Tesisat tiplerinin kusur talimatları. Metinler bilerek kopya: bir tipin düzeltmesi yalnız kendi metnine yazılır, başka tip o metni görmez.
 * Talimatı olmayan tip GENEL'i kullanır.
 */
class PkTesisatTalimat
{
    // Bölüm başlığı raporun yapısıdır, tipe göre değişmez; yalnız satır numarası verir, metni değiştirmez.
    public const BASLIK = <<<'PROMPT'
Sana bir periyodik kontrol raporunun numaralı satırları verilecek. Raporun ana bölüm başlıklarının satır numarasını yaz. Yalnız başlık satırı, içerik satırı değil.
- kind = kusur: kusur açıklamaları bölümü (başlıkta NOTLAR da yazsa).
- kind = not: yalnız notlar bölümü.
- kind = sonuc: sonuç ve kanaat bölümü.
- kind = diger: raporun öteki bütün ana bölümleri (firma, ekipman, ölçüm, yetkili kişi, onay gibi).
PROMPT;

    public const TABLO_TOPRAKLAMA = <<<'PROMPT'
Sana bir topraklama tesisatı raporundan TEK tablo verilecek. liste alanını yaz.
- var: tablonun herhangi bir yerinde ölçüm listesi var; üstünde tesis bilgisi ya da kontrol maddeleri olsa da. Ölçüm listesinin sütunları ölçüm noktası ve ölçülen değerdir (örn. "Sıra No | Ölçüm Noktası | In A | Ölçülen Değer Ω | Sınır Değer Ω | SONUÇ"). Başlığı önceki sayfada kalmış, sıra no ile devam eden ölçüm satırları da budur.
- yok: tabloda ölçülen değer sütunu ve ölçüm satırı yok. Yalnız rapor numarası, tarih, müşteri, adres, telefon, not ya da imza yazan tablo yoktur (örn. "SGS Dosya Numarası: | 25-0302", "Müşteri Adı / Ünvan:", "NOTLAR:", "SONUÇLAR:", "MUAYENE PERSONELİ"). Not cümlesinde "sınır değer" geçmesi liste değildir.
PROMPT;

    public const LISTE_TOPRAKLAMA = 'Sütunlarında ölçüm noktası ve ölçülen değer olan liste, üstünde kontrol maddeleri görünse de ölçüm sistemidir (örn. Topraklama Ölçümleri); Gözle Kontroller değildir.';

    public const SONUC_SUTUNU_TOPRAKLAMA = <<<'PROMPT'
Sana bir topraklama tesisatı raporundaki ölçüm listesinin sütun başlıkları ve ilk satırları verilecek. sutun alanını yaz.
- sutun: her ölçüm satırının sonucunu (uygun / uygun değil) yazan sütunun başlığı, listeden AYNEN. Ölçülen değer, sınır değer, akım, eğri tipi ve not sütunları sonuç değildir; hücrelerinde yalnız rakam ya da birim olan sütun sonuç değildir.
- Satırların sonucunu yazan sütun yoksa "yok".
PROMPT;

    public const BASLIK_YANGIN = <<<'PROMPT'
Sana bir yangın tesisatı periyodik kontrol raporunun başlık satırları verilecek. Her alan için listeden tam satırı seç, listede yoksa null. Aynı satırı iki alana yazma.
- kusur: başlığında KUSUR ya da TESPİT yazan satır (örn. "8. KUSUR AÇIKLAMALARI / NOTLAR", "6. TESPİT VE BULGULAR").
- not: başlığında KUSUR yazmayan, yalnız NOTLAR yazan satır (örn. "7. NOTLAR"). Yoksa null.
- sonuc: başlığında SONUÇ yazan satır (örn. "8. SONUÇ VE KANAAT").
- karar: null.
PROMPT;

    public const OLCUM_SONUC_TOPRAKLAMA = <<<'PROMPT'
Sana bir topraklama tesisatı raporunun tablo satırları verilecek. uygunsuz alanını yaz.
- var: ölçüm listesinde sonucu UYGUN DEĞİL olan ölçüm noktası satırı var (örn. "| 1 | Dağıtım Panosu | 160 | ... | 0.84 | 0.14 | UYGUN DEĞİL |").
- yok: böyle bir ölçüm satırı yok. Kontrol maddeleri (örn. "Topraklama projesi mevcut mu? | U.D") ölçüm satırı değildir.
PROMPT;

    public const BASLIK_TOPRAKLAMA = <<<'PROMPT'
Sana bir topraklama tesisatı periyodik kontrol raporunun başlık satırları verilecek. Her alan için listeden tam satırı seç, listede yoksa null. Aynı satırı iki alana yazma.
- kusur: başlığında KUSUR yazan satır (örn. "## KUSUR AÇIKLAMALARI:", "6. KUSUR AÇIKLAMALARI").
- not: başlığında KUSUR yazmayan, yalnız NOTLAR yazan satır (örn. "## NOTLAR:").
- sonuc: başlığında SONUÇ yazan satır (örn. "## SONUÇLAR:", "8. SONUÇ VE KANAAT").
- karar: kullanılması uygun ya da uygun değil diyen karar satırı. Karar satırı başlıklar arasında yoksa null.
PROMPT;

    public const BASLIK_PARATONER = <<<'PROMPT'
Sana bir paratoner periyodik kontrol raporunun başlık satırları verilecek. Her alan için listeden tam satırı seç, yoksa null.
- kusur: kusur açıklamaları bölümünün başlığı.
- sonuc: sonuç ve kanaat bölümünün başlığı.
- karar: kullanılması uygun ya da uygun değil diyen karar satırı. Karar satırı başlıklar arasında yoksa null.
PROMPT;

    public const BASLIK_ELEKTRIK = <<<'PROMPT'
Sana bir elektrik iç tesisatı periyodik kontrol raporunun başlık satırları verilecek. Her alan için listeden tam satırı seç, yoksa null.
- kusur: başlığında KUSUR yazan satır. Başlığında KUSUR yazmıyorsa null.
- not: başlığında KUSUR yazmayan, yalnız NOTLAR yazan satır.
- sonuc: sonuç ve kanaat bölümünün başlığı.
- karar: kullanılması uygun ya da uygun değil diyen karar satırı. Karar satırı başlıklar arasında yoksa null.
PROMPT;

    public const BASLIK_DAVLUMBAZ = <<<'PROMPT'
Sana bir davlumbaz / gazlı söndürme sistemi periyodik kontrol raporunun başlık satırları verilecek. Her alan için listeden tam satırı seç, listede yoksa null.
- kusur: başlığında KUSUR ya da NOTLAR yazan satır (örn. "NOTLAR:").
- not: null.
- sonuc: başlığında SONUÇ yazan satır (örn. "SONUÇ:").
- karar: null.
PROMPT;

    public const KARAR_SATIRI = <<<'PROMPT'
Sana bir periyodik kontrol raporunun sonuç bölümünün satırları ve raporun kararı verilecek. Kullanımın uygun ya da uygun değil olduğunu söyleyen ve bu karara uyan satırı listeden seç.
PROMPT;

    public const OLCUM_PARATONER = <<<'PROMPT'
Sana bir paratoner periyodik kontrol raporunun satırları verilecek. Topraklama Ölçüm Değeri (Ω) etiketinin ve Topraklama Sınır Değeri (Ω) etiketinin değeri olan satırı listeden seç. Değer, etiketin hemen altındaki sayıdır. Yoksa null.
PROMPT;

    private const GENEL = <<<'PROMPT'
Sana bir periyodik kontrol raporunun kusur açıklamaları ve notlar bölümleri verilecek. Yalnız bulguları yaz.
- Bulgu: muayenede BU tesiste bulunan somut eksiklik, uygunsuzluk ya da hasar, ya da bunun için yapılması gerekeni söyleyen cümle.
- Bulgu değil: uygun ya da uygun değil diyen yargı cümlesi, genel hatırlatma, raporun geçerlilik ve imza koşulları, lejant, başlık. Bunları yazma.
- text = cümle, raporda yazdığı gibi tam metin. Her bulgu ayrı öğe; bir satırda iki cümle varsa ayrı değerlendir. Madde numarası metinde kalır.
- system = bulgunun ait olduğu sistem, listeden; hiçbir sisteme ait değilse null (genel bulgu).
- Bulgu yoksa items = [].
PROMPT;

    private const ELEKTRIK = <<<'PROMPT'
Sana bir elektrik iç tesisatı periyodik kontrol raporunun kusur ya da sonuç bölümü verilecek. Metindeki HER cümleyi ayrı öğe yaz; bir satırda birkaç cümle varsa hepsini ayrı yaz.
- text = cümle, raporda yazdığı gibi tam metin.
- tur = bulgu: muayenede BU tesiste bulunan somut eksiklik, uygunsuzluk ya da hasar (örn. "Ana panoda etiketleme bulunmamaktadır.", "Pano kapağı bulunmamaktadır.").
- tur = karar: tesisatın kullanımı uygun ya da uygun değil diyen genel yargı cümlesi.
- tur = bilgi: raporun geçerliliği, nüsha sayısı, sorumluluk, genel hatırlatma, başlık.
- system = bulgunun ait olduğu sistem, listeden; hiçbir sisteme ait değilse null (genel bulgu).
PROMPT;

    private const ALGILAMA = <<<'PROMPT'
Sana bir yangın algılama ve uyarı sistemi periyodik kontrol raporunun kusur ya da sonuç bölümü verilecek. Metindeki HER cümleyi ayrı öğe yaz; bir satırda birkaç cümle varsa hepsini ayrı yaz.
- text = cümle, raporda yazdığı gibi tam metin; madde kodu (6.A.7.) ve parantez içi metin dahil.
- tur = bulgu: muayenede BU tesiste bulunan somut eksiklik, arıza ya da yapılması gereken (örn. "6.A.7. Panele bakıldığında 6 adet duman dedektörünün değişimi gerekmektedir.", "Sistem aktif değildir.").
- tur = karar: sistemin kullanımı uygun ya da uygun değil diyen genel yargı cümlesi.
- tur = bilgi: raporun geçerliliği, sorumluluk, genel hatırlatma, başlık.
- system = bulgunun ait olduğu sistem, listeden; hiçbir sisteme ait değilse null (genel bulgu).
PROMPT;

    private const AKU = <<<'PROMPT'
Sana bir akümülatör / UPS tesisatı periyodik kontrol raporunun kusur ya da sonuç bölümü verilecek. Metindeki HER cümleyi ayrı öğe yaz; bir satırda birkaç cümle varsa hepsini ayrı yaz.
- text = cümle, raporda yazdığı gibi tam metin.
- tur = bulgu: muayenede BU tesiste bulunan somut eksiklik, arıza ya da yapılması gereken.
- tur = karar: tesisatın kullanımı uygun ya da uygun değil diyen genel yargı cümlesi.
- tur = bilgi: tesisi tanıtan bilgi (cihaz sayısı gibi), raporun geçerliliği, sorumluluk, genel hatırlatma, başlık.
- system = bulgunun ait olduğu sistem, listeden; hiçbir sisteme ait değilse null (genel bulgu).
PROMPT;

    private const DAVLUMBAZ = <<<'PROMPT'
Sana bir davlumbaz / gazlı söndürme sistemi periyodik kontrol raporunun notlar bölümü verilecek. Metindeki HER satırı ayrı öğe yaz.
- text = satır, raporda yazdığı gibi tam metin.
- tur = bulgu: muayenede ulaşılamayan, görülemeyen, test edilemeyen ya da eksik olan şey (örn. "-PROJEYE ULAŞILAMADI.", "-ALARM VE FLANŞÖRLER TEST EDİLEMEDİ").
- tur = karar: sistemin kullanımı uygun ya da uygun değil diyen genel yargı cümlesi.
- tur = bilgi: sözleşme ve sicil numarası, raporun geçerliliği, genel hatırlatma, başlık.
- system = bulgunun ait olduğu sistem, listeden: proje ve bakım kaydı eksiği belge ve kayıt sistemine, alarm ve flaşör gazlı söndürme sistemine; hiçbir sisteme ait değilse null (genel bulgu).
PROMPT;

    private const TOPRAKLAMA = <<<'PROMPT'
Sana bir topraklama tesisatı periyodik kontrol raporunun kusur ya da sonuç bölümü verilecek. Metindeki HER cümleyi ayrı öğe yaz; bir satırda birkaç cümle varsa hepsini ayrı yaz. Cümle noktada biter, virgülde bölme (örn. "Yapılan kontrolde projesi olmadığı,rölenin aktif olmadığı gözlemlenmiştir" tek cümledir).
- text = cümle, raporda yazdığı gibi tam metin.
- tur = bulgu: bu tesiste bulunan eksiklik, uygunsuzluk ya da uyulması gereken zorunluluk (örn. "İnsan temasının olduğu yerlerde 300 mA ve 30 mA değerinde artık akım anahtarı kullanılması zorunludur.", "Mescit prizde topraklama bulunmamaktadır.", "Garaj tali panosunda sıfırlama yapılmıştır.", "...topraklama direnç değeri UYGUN DEĞİLDİR.").
- tur = karar: tesisatın kullanımı uygun ya da uygun değil diyen genel yargı cümlesi.
- tur = bilgi: genel bakım hatırlatması (örn. "1-Kaçak akım röleleri her ay test butonu vesilesi ile test edilmelidir."), raporun geçerliliği ve imza koşulları (örn. "2-Bu rapor yapılan ölçüm bilgilerini içermektedir..."), kısaltma açıklaması, başlık.
- system = bulgunun ait olduğu sistem, listeden. Pano, priz, gövde topraklaması ve artık akım anahtarı (örn. "Ana dağıtım panosunda artık akım anahtarı bulunmamaktadır.", "İnsan temasının olduğu yerlerde 300 mA ve 30 mA değerinde artık akım anahtarı kullanılması zorunludur.") Topraklama Ölçümleri sistemidir. Proje eksiği Gözle Kontroller sistemidir. Hiçbir sisteme ait değilse null (genel bulgu).
PROMPT;

    public static function kusur(string $slug): string
    {
        return match ($slug) {
            'elektrik-ic-tesisati' => self::ELEKTRIK,
            'topraklama' => self::TOPRAKLAMA,
            'davlumbaz' => self::DAVLUMBAZ,
            'yangin-algilama' => self::ALGILAMA,
            'akumulator' => self::AKU,
            default => self::GENEL,
        };
    }
}
