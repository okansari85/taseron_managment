# Taşeron Management API Documentation

> Backend API dokümantasyonu. Bu doküman endpoint'lerin yalnızca ne yaptığını değil, backend'deki gerçek iş akışını ve domain kurallarını da açıklar.

## 1. Genel Mimari

```text
HTTP Request
  ↓
Sanctum Authentication
  ↓
Role Check (super-admin)
  ↓
Tenant Resolution (X-Tenant-ID)
  ↓
Controller
  ↓
FormRequest Validation
  ↓
Service
  ↓
Repository / Eloquent
  ↓
Database
```

Controller katmanı mümkün olduğunca ince tutulur. İş kuralları Service katmanındadır.

## 2. Authentication

### POST `/api/login`

Kullanıcı girişi yapar ve Sanctum API token üretir.

Request:

```json
{
  "email": "admin@example.com",
  "password": "secret"
}
```

Başarılı response:

```json
{
  "message": "Login successful.",
  "token": "TOKEN",
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@example.com",
    "roles": ["super-admin"]
  }
}
```

### POST `/api/logout`

Authenticated kullanıcının mevcut access token'ını siler.

### GET `/api/user`

Authenticated kullanıcıyı döndürür.

---

# 3. Tenant

Tenant uygulamadaki ana izolasyon sınırıdır. Organization ağacının kendisi Tenant değildir; Tenant'ın içinde Organization ağacı bulunur.

## Tenant Header

Tenant'a bağlı endpoint'lerde aktif tenant:

```http
X-Tenant-ID: 5
```

header'ı ile belirlenir.

## GET `/api/tenants`

Tenant listesini döndürür. Super-admin endpointidir.

## POST `/api/tenants`

Yeni tenant oluşturur.

## GET `/api/tenants/{tenant}`

Tenant detayını ve tenant'ın root organization bilgisini döndürür.

## PUT/PATCH `/api/tenants/{tenant}`

Tenant bilgilerini günceller. Logo gönderilmişse tenant logo storage alanına yüklenir.

## DELETE `/api/tenants/{tenant}`

Tenant'ı siler ve ilişkili logo dosyasını temizler.

---

# 4. Tenant Onboarding

## POST `/api/tenant-onboarding`

Tenant başlangıç yapısını tek transaction içinde oluşturmak için kullanılır.

Desteklenen `onboarding_type` değerleri:

- `holding`
- `group`
- `company`

Request temel yapısı:

```json
{
  "onboarding_type": "company",
  "tenant": {
    "name": "ABC",
    "slug": "abc"
  },
  "organization": {
    "name": "ABC Grup"
  },
  "company": {
    "name": "ABC A.Ş.",
    "company_type": "corporate"
  },
  "location": {
    "name": "Merkez"
  }
}
```

### Holding

Tenant + Holding Organization oluşturur.

### Group

Tenant + Group Organization oluşturur.

### Company

Tenant + Organization + BusinessEntity(type=company) + Company oluşturur ve Company'yi Organization'a bağlar.

`company_type=individual` ise ilk/merkez Location otomatik oluşturulur.

`company_type=corporate` ise onboarding sırasında Location otomatik oluşturulmaz; sonradan oluşturulabilir.

Logo opsiyoneldir ve jpg/jpeg/png/svg kabul edilir.

---

# 5. Organization

Organization Tenant içindeki hiyerarşik yapıdır.

Örnek:

```text
Holding
 ├── Group A
 │    ├── Company 1
 │    └── Company 2
 └── Group B
```

## CRUD

```text
GET    /api/organizations
POST   /api/organizations
GET    /api/organizations/{organization}
PUT    /api/organizations/{organization}
DELETE /api/organizations/{organization}
```

### POST `/api/organizations`

Organization oluşturur.

`parent_id` verilmezse tenant'ın mevcut root organization'ı parent olarak kullanılabilir.

### Group kuralları

`type=group` için parent yalnızca `holding` veya `group` olabilir.

### Update kuralları

- Organization kendi kendisinin parent'ı olamaz.
- Aynı tenant içinde ikinci root organization oluşturulamaz.
- Organization başka tenant'a taşınamaz.

---

# 6. Company

Company, Tenant'a doğrudan değil BusinessEntity üzerinden bağlıdır.

```text
BusinessEntity(type=company)
        ↓
      Company
```

## CRUD

```text
GET    /api/companies
POST   /api/companies
GET    /api/companies/{company}
PUT    /api/companies/{company}
DELETE /api/companies/{company}
```

### Company create

Backend transaction içinde önce BusinessEntity oluşturur, ardından Company kaydını bu BusinessEntity'ye bağlar.

Temel alanlar:

```json
{
  "name": "ABC A.Ş.",
  "short_name": "ABC",
  "description": "Açıklama",
  "company_type": "corporate",
  "is_active": true
}
```

`company_type`:

- `individual`
- `corporate`

### Önemli not

Service ve model `short_name`, `description` ve `is_active` alanlarını desteklemektedir. Request validation katmanı da bu alanları kabul etmelidir; aksi durumda validated data içine girmedikleri için frontend'den gönderilen değerler service'e ulaşmaz.

---

# 7. Contractor

Contractor da BusinessEntity havuzunu kullanır.

```text
BusinessEntity(type=contractor)
        ↓
    Contractor
```

## CRUD

```text
GET    /api/contractors
POST   /api/contractors
GET    /api/contractors/{contractor}
PUT    /api/contractors/{contractor}
DELETE /api/contractors/{contractor}
```

`contractor_type` değerleri:

- `permanent`
- `temporary`

İsim BusinessEntity üzerinden tutulur.

---

# 8. Location

Location Tenant'a bağlı fiziksel/operasyonel noktadır.

## CRUD

```text
GET    /api/locations
POST   /api/locations
GET    /api/locations/{location}
PUT    /api/locations/{location}
DELETE /api/locations/{location}
```

Create sırasında tenant_id frontend'den alınmaz; aktif TenantContext kullanılır.

Temel request:

```json
{
  "name": "İstanbul Merkez"
}
```

---

# 9. Organization ↔ Company

Company'nin Organization hiyerarşisine bağlandığı ilişkidir.

```text
GET    /api/organization-companies
GET    /api/organizations/{organization}/companies
PUT    /api/organizations/{organization}/companies
POST   /api/organizations/{organization}/companies/{businessEntity}
DELETE /api/organizations/{organization}/companies/{businessEntity}
```

### POST attach

Mevcut BusinessEntity'yi organization'a bağlar.

Kurallar:

- Organization `type=group` olmalıdır.
- BusinessEntity `type=company` olmalıdır.
- Organization ve BusinessEntity aynı tenant'a ait olmalıdır.
- Bir company aynı anda yalnızca bir group'a bağlı olabilir.

### PUT sync

Company listesini topluca senkronize eder.

Request:

```json
{
  "business_entity_ids": [10, 11, 12]
}
```

Gönderilen liste organization'ın güncel company ilişkisi olarak kabul edilir.

---

# 10. Organization ↔ Location

```text
GET    /api/organizations/{organization}/locations
POST   /api/organizations/{organization}/locations
POST   /api/organizations/{organization}/locations/{location}
DELETE /api/organizations/{organization}/locations/{location}
```

İki farklı kullanım vardır:

- Mevcut Location attach etmek.
- Organization altında yeni Location oluşturmak.

Yeni Location oluşturma transaction içinde yapılır ve oluşturulan kayıt organization'a bağlanır.

---

# 11. Brand

Brand Tenant seviyesinde marka kaydıdır.

## CRUD

```text
GET    /api/brands
POST   /api/brands
GET    /api/brands/{brand}
PUT    /api/brands/{brand}
DELETE /api/brands/{brand}
```

Create sırasında company ilişkileri opsiyonel olarak verilebilir:

```json
{
  "name": "ABC Brand",
  "company_ids": [10, 11]
}
```

Seçilen company'lerin aktif tenant'a ait olması gerekir.

---

# 12. Brand ↔ Location

```text
GET    /api/brands/{brand}/locations
PUT    /api/brands/{brand}/locations
POST   /api/brands/{brand}/locations/{location}
DELETE /api/brands/{brand}/locations/{location}
```

### Ana iş kuralı

Bir Location aynı anda yalnızca **bir Brand**'e bağlı olabilir.

Attach sırasında Location başka bir Brand'e bağlıysa işlem reddedilir.

### PUT sync

```json
{
  "location_ids": [1, 2, 3]
}
```

Brand'in location listesini topluca senkronize eder.

---

# 13. Operational Region

Location içindeki operasyonel alanları temsil eder.

```text
GET    /api/locations/{location}/operational-regions
POST   /api/locations/{location}/operational-regions
GET    /api/locations/{location}/operational-regions/{operationalRegion}
PUT    /api/locations/{location}/operational-regions/{operationalRegion}
DELETE /api/locations/{location}/operational-regions/{operationalRegion}
```

`type` değerleri:

- `facility`
- `warehouse`
- `business`
- `depot`
- `office`
- `store`

Create:

```json
{
  "name": "Üretim Alanı",
  "type": "facility",
  "is_active": true
}
```

Operational Region mutlaka ilgili Location'a ait olmalıdır.

---

# 14. Location ↔ BusinessEntity

Bu ilişki sistemin en önemli ilişki katmanlarından biridir.

```text
GET    /api/locations/{location}/business-entities
POST   /api/locations/{location}/business-entities
PUT    /api/locations/{location}/business-entities/{businessEntity}
DELETE /api/locations/{location}/business-entities/{businessEntity}
```

İlişki pivot tablosu:

```text
location_business_entities
```

Pivot üzerinde operasyonel bilgiler tutulur:

- `operational_region_id`
- `nace_code`
- `hazard_class`
- `sgk_workplace_number`

Brand ilişkileri ayrıca:

```text
location_business_entity_brands
```

pivotu üzerinden tutulur.

### Create request

```json
{
  "business_entity_id": 10,
  "brand_ids": [2, 3],
  "operational_region_id": 4,
  "nace_code": "10.11",
  "hazard_class": "Tehlikeli",
  "sgk_workplace_number": "123456"
}
```

### Company bağlama

BusinessEntity `type=company` olmalıdır.

Seçilen Brand'ler ilgili Company'ye bağlı olmalıdır.

Operational Region ilgili Location'a ait olmalıdır.

### Contractor bağlama

BusinessEntity `type=contractor` olmalıdır.

Contractor kaydı bulunmalıdır.

`contractor_type=temporary` olan Contractor Location'a bağlanamaz.

Bu nedenle:

```text
Permanent Contractor → Location'a bağlanabilir
Temporary Contractor → Location'a bağlanamaz
```

---

# 15. Tenant Isolation

Tenant izolasyonu iki ana mekanizma ile sağlanır.

## TenantContext

`ResolveTenant` middleware aktif tenant'ı `X-Tenant-ID` header'ından çözer.

Service katmanında:

```php
TenantContext::id()
```

ile aktif tenant alınır.

## TenantScope

Organization ve BusinessEntity gibi modellerde global tenant scope kullanılır.

Bu sayede normal Eloquent sorguları aktif tenant dışındaki kayıtları görmez.

Ayrıca bazı Service'ler entity'lerin tenant_id değerlerini açıkça karşılaştırarak ikinci bir kontrol yapar.

---

# 16. Validation

Genel olarak validation FormRequest sınıflarında yapılır.

Örnek:

### Contractor

```text
name              required|string|max:255
contractor_type   required|in:permanent,temporary
```

### Location

```text
name required|string|max:255
```

### Operational Region

```text
name       required|string|max:255
type       required|in:facility,warehouse,business,depot,office,store
is_active  sometimes|boolean
```

### Location BusinessEntity

```text
business_entity_id       required|integer|exists
brand_ids                sometimes|array
operational_region_id    nullable|integer|exists
nace_code                required|string|max:50
hazard_class             required|string|max:100
sgk_workplace_number     nullable|string|max:50
```

---

# 17. Genel HTTP Durumları

```text
200 OK                  Başarılı GET/update
201 Created              Yeni kayıt oluşturuldu
401 Unauthorized        Authentication gerekli/geçersiz
403 Forbidden            Yetki yok
404 Not Found            Kayıt bulunamadı
422 Unprocessable Entity Validation hatası
500 Server Error         Beklenmeyen backend hatası
```

---

# 18. Domain Özeti

Sistemin temel veri modeli:

```text
Tenant
 │
 ├── Organization Tree
 │      ├── Holding
 │      ├── Group
 │      └── Company ilişkileri
 │
 ├── BusinessEntity Pool
 │      ├── Company
 │      └── Contractor
 │
 ├── Location
 │      └── LocationBusinessEntity
 │              ├── NACE
 │              ├── Hazard Class
 │              ├── SGK Workplace Number
 │              ├── Operational Region
 │              └── Brands
 │
 └── Brand
```

### Kritik ilişkiler

```text
Organization ──< Organization ↔ Company >── BusinessEntity ── Company

Organization ──< Organization ↔ Location >── Location

Company ──< Company ↔ Brand >── Brand

Brand ──< Brand ↔ Location >── Location

Location ──< LocationBusinessEntity >── BusinessEntity
```

Bu yapıdaki ana fikir şudur: **Tenant izolasyon sınırıdır; Organization ise Tenant içerisindeki kurumsal hiyerarşidir. Company ve Contractor merkezi BusinessEntity havuzu üzerinden yönetilir. Location üzerindeki gerçek operasyonel ilişki LocationBusinessEntity pivotunda tutulur.**
