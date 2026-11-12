<?php

namespace App\Console\Commands;

use App\Mail\PkFindingReminderMail;
use App\Models\PkFinding;
use App\Models\PkFindingEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Bulgu Takip (2026-11-12): termin hatırlatması, her gün (routes/console.php). Sorumluya termin 10 gün kala, 3 gün kala ve termin
 * geçince birer e-posta; aynı termin için her biri bir kez (geçmişte 'hatirlatma' olayı). Gün kaçarsa sıradaki aralıkta gider.
 */
class PkFindingReminders extends Command
{
    protected $signature = 'pk:finding-reminders';

    protected $description = 'Bulgu Takip termin hatırlatma e-postalarını gönderir';

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $sent = 0;
        PkFinding::withoutGlobalScopes()->where('status', '!=', 'kapandi')->whereNotNull('due_date')->whereNotNull('responsible_user_id')
            ->where('due_date', '<=', $today->addDays(10)->toDateString())
            ->orderBy('id')->chunkById(200, function ($findings) use ($today, &$sent) {
                foreach ($findings as $finding) {
                    $days = (int) $today->diffInDays(CarbonImmutable::parse($finding->due_date), false);
                    $kind = $days < 0 ? 'gecti' : ($days <= 3 ? '3' : '10');
                    $due = $finding->due_date->format('Y-m-d');
                    $already = PkFindingEvent::query()->where('pk_finding_id', $finding->id)->where('type', 'hatirlatma')->get()
                        ->contains(fn (PkFindingEvent $event) => ($event->meta['kind'] ?? null) === $kind && ($event->meta['due_date'] ?? null) === $due);
                    $user = User::query()->find($finding->responsible_user_id);
                    if ($already || !$user?->email) {
                        continue;
                    }
                    // Bir e-posta hatası ötekileri durdurmaz; gönderilemeyen yarın yeniden denenir.
                    $ok = rescue(function () use ($finding, $user, $kind, $days) {
                        Mail::to($user->email)->send(new PkFindingReminderMail($finding, $kind, $days, $this->url()));

                        return true;
                    }, false);
                    if ($ok) {
                        PkFindingEvent::query()->create([
                            'pk_finding_id' => $finding->id, 'type' => 'hatirlatma',
                            'note' => "{$user->name} kişisine termin hatırlatması gönderildi.",
                            'meta' => ['kind' => $kind, 'due_date' => $due, 'user_id' => $user->id],
                        ]);
                        $sent++;
                    }
                }
            });
        $this->info("{$sent} hatırlatma gönderildi.");

        return self::SUCCESS;
    }

    private function url(): string
    {
        return rtrim((string) env('FRONTEND_URL', env('APP_URL')), '/') . '/expert/bulgu-takip';
    }
}
