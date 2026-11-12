<?php

namespace App\Mail;

use App\Models\PkFinding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Bulgu Takip termin hatırlatması (pk:finding-reminders): kind '10' / '3' gün kala, 'gecti' termin geçti.
class PkFindingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PkFinding $finding,
        public readonly string $kind,
        public readonly int $days,
        public readonly string $url,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === 'gecti'
            ? 'PKTakip: bulgunun termini geçti'
            : "PKTakip: bulgunun terminine {$this->days} gün kaldı");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.pk-finding-reminder');
    }
}
