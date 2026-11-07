<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// PKTakip PayTR siparişi onayı (2026-11-07): Mesafeli Sözleşmeler Yönetmeliği gereği sözleşme teyidi alıcıya e-postayla
// (kalıcı veri saklayıcısı) gönderilir. $order: pk_payment_orders satırı.
class PkOrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly object $order,
        public readonly string $packageName,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'PKTakip siparişiniz onaylandı — ' . $this->order->merchant_oid,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.pk-order-confirmation');
    }
}
