<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// pktakip Bulgu Takip (2026-11-12): termin hatırlatma e-postaları; sunucuda crontab'da schedule:run gerekir.
\Illuminate\Support\Facades\Schedule::command('pk:finding-reminders')->dailyAt('08:00')->timezone('Europe/Istanbul');
