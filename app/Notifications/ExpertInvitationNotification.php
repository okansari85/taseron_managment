<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExpertInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(private string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');
        $url = $frontendUrl
            . '/set-password?token=' . urlencode($this->token)
            . '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('PKTakip uzman hesabınız hazır')
            ->greeting('Merhaba ' . $notifiable->name . ',')
            ->line('PKTakip uzman hesabınız yönetici tarafından oluşturuldu.')
            ->line('Sisteme giriş yapabilmek için aşağıdaki bağlantıdan kendi şifrenizi belirleyin.')
            ->action('Şifremi Belirle', $url)
            ->line('Bu bağlantı 60 dakika boyunca geçerlidir.')
            ->line('Bu e-postayı siz beklemiyorsanız dikkate almayabilirsiniz.');
    }
}
