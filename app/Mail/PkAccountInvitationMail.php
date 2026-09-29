<?php

namespace App\Mail;

use App\Models\ExpertInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// PKTakip OSGB / kurumsal hesap daveti (hesabın yöneticisine); şifre belirleme bağlantısı uzman davetiyle aynı. Bireysel
// uzman daveti ExpertInvitationMail ile aynen kalır.
class PkAccountInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    private const LABELS = ['osgb' => 'OSGB', 'corporate' => 'kurumsal'];

    public function __construct(
        public readonly ExpertInvitation $invitation,
        public readonly string $acceptUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'PKTakip ' . $this->label() . ' hesabınız hazır',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pk-account-invitation',
            with: ['label' => $this->label()],
        );
    }

    private function label(): string
    {
        return self::LABELS[$this->invitation->tenant?->tenant_type] ?? 'OSGB';
    }
}
