<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DiscConviteNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $link,
        protected string $empresaNome,
        protected \DateTimeInterface $expiraEm,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Convite para teste de perfil DISC')
            ->greeting('Olá!')
            ->line("A empresa {$this->empresaNome} convidou você para realizar um teste de perfil comportamental (DISC).")
            ->line('O teste leva cerca de 10 minutos e não exige login.')
            ->action('Realizar o teste', $this->link)
            ->line('Este link expira em ' . $this->expiraEm->format('d/m/Y') . ' e pode ser usado uma única vez.')
            ->salutation('Equipe Envia Currículo');
    }
}
