<?php

namespace App\Mail;

use App\Models\ExpertInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExpertInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ExpertInvitation $invitation,
        public readonly string $acceptUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'PKTakip uzman hesabınız hazır',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.expert-invitation',
        );
    }
}
