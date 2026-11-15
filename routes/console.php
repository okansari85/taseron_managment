<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// pktakip Bulgu Takip (2026-11-12): termin hatırlatma e-postaları; sunucuda crontab'da schedule:run gerekir.
\Illuminate\Support\Facades\Schedule::command('pk:finding-reminders')->dailyAt('08:00')->timezone('Europe/Istanbul');
// pktakip panel bildirimleri (2026-11-15): kontrol tarihi yaklaşan / geçen, bulgu termini.
\Illuminate\Support\Facades\Schedule::command('pk:notifications-generate')->dailyAt('08:00')->timezone('Europe/Istanbul');
