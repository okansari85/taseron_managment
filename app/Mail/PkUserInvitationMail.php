<?php

namespace App\Mail;

use App\Models\ExpertInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// PKTakip OSGB / kurumsal hesabına kullanıcı daveti (yönetici ekler): hesap adı ve rol; şifre belirleme uzman davetiyle aynı.
class PkUserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ExpertInvitation $invitation,
        public readonly string $acceptUrl,
        public readonly string $roleLabel,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'PKTakip: ' . $this->invitation->tenant?->name . ' hesabına davet edildiniz',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.pk-user-invitation');
    }
}
