<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Mail messages and notifications, with the time between sending and sent.
 */
final class MailLens implements Advises, Lens, Resettable, Summarizable
{
    private Eyepiece $eyepiece;

    /** @var array<string, float> */
    private array $pending = [];

    public function key(): string
    {
        return 'mail';
    }

    public function description(): string
    {
        return 'Mail and notifications sent during the capture. Mail rows: mailable, subject, from/to/cc/bcc, attachments, body_bytes, duration. Notification rows: notification class, channel, notifiable (Class#id), response, duration.';
    }

    public function advice(): string
    {
        return 'Look here to confirm which mails and notifications were sent, to whom, and whether they were queued.';
    }

    public function boot(Application $app, Eyepiece $eyepiece): void
    {
        $this->eyepiece = $eyepiece;

        $eyepiece->listen(MessageSending::class, function (MessageSending $event): void {
            $this->pending['mail:'.spl_object_id($event->message)] = $this->eyepiece->now();
        });

        $eyepiece->listen(MessageSent::class, function (MessageSent $event): void {
            $message = $event->message;
            $started = $this->pending['mail:'.spl_object_id($message)] ?? null;
            unset($this->pending['mail:'.spl_object_id($message)]);

            if (! $this->eyepiece->recording() || ! $message instanceof Email) {
                return;
            }

            $this->eyepiece->push('mail', [
                'type' => 'mail',
                'mailable' => $event->data['__laravel_mailable'] ?? null,
                'subject' => $message->getSubject(),
                'from' => $this->addresses($message->getFrom()),
                'to' => $this->addresses($message->getTo()),
                'cc' => $this->addresses($message->getCc()),
                'bcc' => $this->addresses($message->getBcc()),
                'attachments' => count($message->getAttachments()),
                'body_bytes' => strlen((string) $message->getTextBody()) + strlen((string) $message->getHtmlBody()),
                'duration' => $started === null ? null : round($this->eyepiece->now() - $started, 3),
            ]);
        });

        $eyepiece->listen(NotificationSending::class, function (NotificationSending $event): void {
            $this->pending[$this->notificationKey($event->notification, $event->channel)] = $this->eyepiece->now();
        });

        $eyepiece->listen(NotificationSent::class, function (NotificationSent $event): void {
            $key = $this->notificationKey($event->notification, $event->channel);
            $started = $this->pending[$key] ?? null;
            unset($this->pending[$key]);

            if (! $this->eyepiece->recording()) {
                return;
            }

            $this->eyepiece->push('mail', [
                'type' => 'notification',
                'notification' => $event->notification::class,
                'channel' => $event->channel,
                'notifiable' => Normalizer::value($event->notifiable),
                'response' => Normalizer::value($event->response),
                'duration' => $started === null ? null : round($this->eyepiece->now() - $started, 3),
            ]);
        });
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    public function summarize(Capture $capture): array
    {
        $rows = $capture->rows('mail');

        if ($rows === []) {
            return [];
        }

        return [
            'totals' => [
                'mail_sent' => count(array_filter($rows, fn (array $row): bool => $row['type'] === 'mail')),
                'notifications_sent' => count(array_filter($rows, fn (array $row): bool => $row['type'] === 'notification')),
            ],
        ];
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): string => $address->toString(), $addresses));
    }

    private function notificationKey(object $notification, string $channel): string
    {
        try {
            $id = $notification->id ?? spl_object_id($notification);
        } catch (Throwable) {
            $id = spl_object_id($notification);
        }

        return 'notification:'.$id.'|'.$channel;
    }
}
