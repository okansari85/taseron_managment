# Taseron Management

## Development Workflow

### İş Yerinde — GitHub Codespaces

Projeyi GitHub Codespaces üzerinden geliştir.

İşten çıkmadan önce geliştirme veritabanının snapshot'ını al:

    php artisan db:snapshot

Snapshot konumu:

    database/snapshots/development.sql

Değişiklikleri GitHub'a gönder:

    git add .
    git commit -m "Development update"
    git push

//
php artisan db:snapshot
git add .
git commit -m "Development update"
git push
//

git pull
php artisan db:restore


### Evde — Local Development

Son değişiklikleri çek:

    git pull

Geliştirme veritabanını son snapshot'tan geri yükle:

    php artisan db:restore

### Database Workflow

Migration
    ↓
Seeder
    ↓
Development Database
    ↓
php artisan db:snapshot
    ↓
database/snapshots/development.sql
    ↓
GitHub
    ↓
git pull
    ↓
php artisan db:restore

> development.sql yalnızca geliştirme/test verileri içermelidir. Production verileri snapshot içerisine alınmamalıdır.
