# CLAUDE.md

Bu dosya, bu repoda (taseron_managment / backend) çalışırken Claude'un uyması gereken proje kurallarını içerir.

## Migration ile şema değişikliği: çalışmış bir migration'ı düzenleme, yeni migration oluştur

Bir tabloya ait migration DAHA ÖNCE ÇALIŞTIRILMIŞSA (yani geliştirme/staging/production ortamında `php artisan migrate` ile zaten uygulanmışsa, `migrations` tablosunda kaydı varsa), o migration dosyasını doğrudan düzenlemek YANLIŞTIR. Dosyayı değiştirmek veritabanındaki gerçek şemayı otomatik güncellemez — Laravel migration'ı zaten "çalıştı" olarak bildiği için `php artisan migrate` bir daha çalıştırmaz. Sonuç: migration dosyasıyla gerçek tablo arasında sessiz bir tutarsızlık oluşur ve kod, veritabanında var olmayan bir kolonu sorgulamaya çalışır.

**Bu tam olarak yaşandı:** `location_experts` tablosunun migration'ı (`2026_09_03_000002_create_location_experts_table.php`) `location_id`'den `location_business_entity_id`'ye değiştirildi, ama tablo daha önce (2 gün önce) migrate edilmiş olduğu için değişiklik veritabanına yansımadı. Sonuç: `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'location_experts.location_business_entity_id'`.

**Kural:**
1. Migration bu oturumda/hiç çalıştırılmadıysa (henüz `php artisan migrate` ile uygulanmadığından eminsen) — mevcut migration dosyasını doğrudan düzenlemek serbest, bu en temiz yoldur.
2. Migration DAHA ÖNCE çalıştırılmışsa — o dosyayı ASLA düzenleme. Bunun yerine üstüne yeni bir migration dosyası oluştur (örn. `2026_09_05_000001_change_location_id_to_location_business_entity_id_on_location_experts_table.php`), içinde `Schema::table(...)` ile `up()`'ta gerekli `dropColumn`/`renameColumn`/`addColumn`/foreign key değişikliğini yaz, `down()`'da da tersini tanımla.
3. Migration'ın çalışıp çalışmadığından emin değilsen: varsayım yapma. Migration dosyasının oluşturulma/değiştirilme tarihine, konuşma geçmişine bak; hâlâ emin olamıyorsan kullanıcıya sor. Yanılma payı varsa yeni migration oluşturmak her zaman daha güvenli olan seçenektir — gereksiz bir migration dosyası zararsızdır, ama çalışmış bir migration'ı sessizce değiştirmek üretim/diğer ortamlarda veri kaybına veya "schema drift"e yol açabilir.
4. Değişiklik yapıldıktan sonra kullanıcıya hangi migration'ın çalıştırılması gerektiğini (`php artisan migrate`) açıkça söyle — bu ortamdan (Claude'un sandbox'ından) kullanıcının gerçek geliştirme veritabanına migration çalıştırma imkanı yok, bu adım her zaman kullanıcı tarafından kendi ortamında yapılmalı.

## Eloquent ilişkileri JSON'a çevrilirken snake_case olur (çok kelimeli ilişki adlarına dikkat)

Bir Eloquent modelinde `public function locationBusinessEntity(): BelongsTo` gibi camelCase isimli bir ilişki tanımlarsan, bu ilişki `with([...])` ile eager-load edilip `response()->json($model)` ile döndürüldüğünde, JSON'daki key ilişkinin metod adıyla AYNI KALMAZ — Eloquent'in `Model::$snakeAttributes` varsayılanı (`true`) sayesinde `Str::snake()` uygulanır. Yani `locationBusinessEntity` → JSON'da `location_business_entity`, `businessEntity` → `business_entity` olur.

Tek kelimelik ilişki adlarında (`location()`, `user()`, `businessEntity()` gibi TEK kelime olanlar hariç — bu örnek çok kelimeli) bu fark görünmez çünkü `Str::snake('location')` zaten `'location'`. Bu yüzden çok kelimeli bir ilişki eklerken frontend tarafında camelCase key beklemek sessizce boş/`undefined` veriye yol açar — hata fırlamaz, sadece `item.locationBusinessEntity` her zaman `undefined` olur ve optional chaining + `??` fallback'leri (`'—'` gibi) her yerde görünür ama neden boş olduğu anlaşılmaz.

**Yaşanan örnek:** `LocationExpert::locationBusinessEntity()` ilişkisi `with(['locationBusinessEntity.location', 'locationBusinessEntity.businessEntity'])` ile yüklendi, ama gerçek JSON key'leri `location_business_entity` ve (onun içinde) `business_entity` çıktı. Frontend tipleri (`AuthorizationLocationExpert`, `AuthorizationLocationBusinessEntity`) camelCase yazılmıştı, bu yüzden "Atanmış Lokasyonlar" listesi lokasyon/firma/NACE/SGK alanlarının hepsini boş (`—`) gösterdi; backend'de hata yoktu, veri de doğruydu, sadece key ismi uyuşmuyordu.

**Kural:** Backend'de yeni, birden fazla kelimeden oluşan bir ilişki eklediğinde (`locationBusinessEntity`, `businessEntityLocation` vb.), frontend tipini/mapping'ini YAZARKEN camelCase değil, `Str::snake()` sonucu olan snake_case key'i kullan (`location_business_entity`, `business_entity`). Emin değilsen, tahmin etme — gerçek response'u kontrol et (örn. tarayıcı DevTools → Network → ilgili istek → Response) ya da `php artisan tinker` ile `$model->load(...)->toArray()` çıktısına bak.
