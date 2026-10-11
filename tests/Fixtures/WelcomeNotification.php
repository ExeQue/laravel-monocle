<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WelcomeNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()->subject('Welcome aboard')->line('Hello there.');
    }
}
