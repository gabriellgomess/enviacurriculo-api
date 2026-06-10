<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $token,
        protected string $painel,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = config("frontends.{$this->painel}", config('frontends.candidato'));
        $url  = $base . '/reset-password?token=' . $this->token
              . '&email=' . urlencode($notifiable->email);

        $expire = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Redefinição de senha')
            ->greeting('Olá!')
            ->line('Recebemos uma solicitação de redefinição de senha para a sua conta.')
            ->action('Redefinir senha', $url)
            ->line("Este link expira em {$expire} minutos.")
            ->line('Se você não solicitou a redefinição, nenhuma ação é necessária.')
            ->salutation('Equipe Envia Currículo');
    }
}
