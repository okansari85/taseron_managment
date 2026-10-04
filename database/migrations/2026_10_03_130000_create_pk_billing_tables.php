<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pktakip paket ve kredi (2026-10-03): paketler (süper admin tanımlar), hesabın aboneliği ve kredi defteri. Yalnızca rapor
 * okuma kredi düşer; ekipman için paketin ayrı aktif ekipman limiti vardır. Mevcut hesaplar paket atanana kadar
 * "Sınırsız (geçiş)". Taşeron tablolarına dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pk_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // expert (bireysel uzman) | osgb | corporate
            $table->string('account_type', 20);
            $table->unsignedInteger('monthly_credits');
            // Boşsa sınırsız (pasif ekipman sayılmaz).
            $table->unsignedInteger('equipment_limit')->nullable();
            $table->decimal('monthly_price', 10, 2)->nullable();
            $table->decimal('yearly_price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pk_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            // unlimited (geçiş) | package | promo (ilk 1000 üye) | none (paketsiz)
            $table->string('mode', 20);
            $table->foreignId('pk_package_id')->nullable()->constrained('pk_packages')->nullOnDelete();
            // Atama anındaki değerler (paket sonradan değişse de hesabınki değişmez).
            $table->unsignedInteger('monthly_credits')->default(0);
            $table->unsignedInteger('equipment_limit')->nullable();
            $table->date('started_at')->nullable();
            // Sıradaki aylık kredi yüklemesi ve yıllık sıfırlama günü.
            $table->date('next_grant_at')->nullable();
            $table->date('resets_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('pk_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // + yükleme / − kullanım
            $table->integer('amount');
            // grant (paket yüklemesi) | promo | reading (rapor okuma) | manual (süper admin) | reset (yıllık sıfırlama)
            $table->string('reason', 20);
            $table->foreignId('pk_report_analysis_id')->nullable()->constrained('pk_report_analyses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->unique('pk_report_analysis_id', 'pce_analysis_unique');
        });

        // Kullanıcının taslak paketleri (ekipman limitleri henüz yok: boş = sınırsız; süper admin ekrandan girer).
        $now = now();
        $packages = [
            ['Mini', 'expert', 20, 100], ['Standart', 'expert', 50, 249], ['Plus', 'expert', 100, 449],
            ['Başlangıç', 'osgb', 250, 899], ['Pro', 'osgb', 500, 1499],
            ['Business', 'corporate', 1000, 2499], ['Enterprise', 'corporate', 2500, 4999],
        ];
        foreach ($packages as $index => [$name, $type, $credits, $price]) {
            DB::table('pk_packages')->insert([
                'name' => $name, 'account_type' => $type, 'monthly_credits' => $credits, 'equipment_limit' => null, 'monthly_price' => $price,
                'yearly_price' => null, 'is_active' => true, 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Mevcut pktakip hesapları kilitlenmesin: paket atanana kadar sınırsız (geçiş).
        foreach (DB::table('tenants')->whereIn('tenant_type', ['expert', 'osgb', 'corporate'])->pluck('id') as $tenantId) {
            DB::table('pk_subscriptions')->insert([
                'tenant_id' => $tenantId, 'mode' => 'unlimited', 'monthly_credits' => 0, 'equipment_limit' => null, 'started_at' => $now->toDateString(),
                'note' => 'Geçiş: paket atanana kadar sınırsız', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pk_credit_entries');
        Schema::dropIfExists('pk_subscriptions');
        Schema::dropIfExists('pk_packages');
    }
};
