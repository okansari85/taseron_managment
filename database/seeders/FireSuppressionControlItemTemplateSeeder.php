<?php

namespace Database\Seeders;

use App\Models\FireSuppressionControlItemTemplate;
use Illuminate\Database\Seeder;

// Kategori bazlı standart periyodik kontrol maddesi listesi — genel kabul
// görmüş yangın söndürme sistemi bakım/kontrol pratiklerini yansıtır,
// belirli bir yönetmelik maddesi numarasını temsil etmez. Tenant'lar
// ihtiyaç duyarsa bu şablonu ileride kendi ekleri için genişletebilir.
class FireSuppressionControlItemTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            'yangin_dolabi' => [
                'Dolap kapağının açılır/kapanır durumu',
                'Hortum bağlantısının sağlamlığı',
                'Hortum çapı ve uzunluğunun uygunluğu',
                'Lansın (nozul) eksiksiz ve çalışır durumda olması',
                'Vana açma-kapama kolunun çalışırlığı',
                'Basınç göstergesinin okunabilirliği',
                'Dolap içi temizlik ve erişilebilirlik',
                'Yönlendirme/işaret levhasının mevcudiyeti',
                'Boru hattı sızdırmazlığı',
                'Su akış testi (debi/basınç)',
            ],
            'sprinkler' => [
                'Sprinkler başlıklarının fiziksel durumu (korozyon, boya, engel)',
                'Boru hattı sızdırmazlık kontrolü',
                'Askı ve destek elemanlarının durumu',
                'Su akış anahtarı (flow switch) testi',
                'Alarm vanası ve donanımlarının kontrolü',
                'Basınç göstergesinin durumu',
                'Kontrol vanalarının konumu ve mühürlenmesi',
                'Yangın suyu besleme hattının kontrolü',
            ],
            'hidrant' => [
                'Hidrant gövdesi ve kapağının durumu',
                'Hidrant çıkış vanalarının çalışırlığı',
                'Hidrant başlığı diş/adaptör uygunluğu',
                'Erişim yolunun açık olması',
                'Basınç testi',
                'Boru hattı sızdırmazlığı',
            ],
            'yangin_pompasi' => [
                'Pompa çalışma testi (otomatik/manuel devreye girme)',
                'Basınç göstergesi ve manometre kontrolü',
                'Jokey pompa çalışma ayarlarının kontrolü',
                'Dizel/elektrik motor durumu, yakıt/akü seviyesi',
                'Emiş ve basma hattı sızdırmazlığı',
                'Vana konumlarının doğruluğu',
                'Titreşim ve gürültü kontrolü',
                'Pompa dairesi havalandırma ve temizlik',
            ],
            'su_deposu' => [
                'Su seviyesi kontrolü',
                'Depo gövdesi sızdırmazlık/korozyon kontrolü',
                'Seviye şamandıra/sensör çalışırlığı',
                'Temizlik/bakım kaydının güncelliği',
            ],
            'gazli_sondurme' => [
                'Gaz tüpü basınç/dolum kontrolü',
                'Boru hattı ve nozul durumu',
                'Dedektör ve alarm panelinin çalışırlığı',
                'Manuel/otomatik devreye alma testi',
                'Oda sızdırmazlığı (gaz tutma testi)',
            ],
            'diger' => [
                'Genel görsel kontrol',
                'Etiketleme ve işaretleme kontrolü',
                'Bakım kaydının güncelliği',
            ],
        ];

        foreach ($items as $category => $titles) {
            foreach ($titles as $index => $title) {
                FireSuppressionControlItemTemplate::query()->updateOrCreate(
                    ['category' => $category, 'code' => sprintf('%s.%d', strtoupper(substr($category, 0, 1)), $index + 1)],
                    ['section' => null, 'title' => $title, 'sort_order' => $index + 1]
                );
            }
        }
    }
}
