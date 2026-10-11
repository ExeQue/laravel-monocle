<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Lenses;

use ExeQue\Monocle\Contracts\Lens;
use ExeQue\Monocle\Contracts\Lenses\Advises;
use ExeQue\Monocle\Contracts\Lenses\Presentable;
use ExeQue\Monocle\Contracts\Lenses\Resettable;
use ExeQue\Monocle\Contracts\Lenses\Summarizable;
use ExeQue\Monocle\Dashboard\Presentation;
use ExeQue\Monocle\Data\Capture;
use ExeQue\Monocle\Data\Summary;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Support\Normalizer;
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
final class MailLens implements Advises, Lens, Presentable, Resettable, Summarizable
{
    /** @var array<string, float> */
    private array $pending = [];

    public function __construct(
        private readonly Eyepiece $eyepiece
    ) {
    }

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

    public function boot(): void
    {
        $this->eyepiece->listen(MessageSending::class, function (MessageSending $event): void {
            $this->pending[$this->mailKey($event->message)] = $this->eyepiece->now();
        });

        $this->eyepiece->listen(MessageSent::class, function (MessageSent $event): void {
            $message = $event->message;
            $key = $this->mailKey($message);
            $started = $this->pending[$key] ?? null;
            unset($this->pending[$key]);

            if (! $this->eyepiece->recording()) {
                return;
            }

            if (! $message instanceof Email) {
                return;
            }

            $bodyBytes = strlen((string) $message->getTextBody()) + strlen((string) $message->getHtmlBody());

            $this->eyepiece->push('mail', [
                'type' => 'mail',
                'mailable' => $event->data['__laravel_mailable'] ?? null,
                'subject' => $message->getSubject(),
                'from' => $this->addresses($message->getFrom()),
                'to' => $this->addresses($message->getTo()),
                'cc' => $this->addresses($message->getCc()),
                'bcc' => $this->addresses($message->getBcc()),
                'attachments' => count($message->getAttachments()),
                'body_bytes' => $bodyBytes,
                'duration' => $this->durationSince($started),
            ]);
        });

        $this->eyepiece->listen(NotificationSending::class, function (NotificationSending $event): void {
            $this->pending[$this->notificationKey($event->notification, $event->channel)] = $this->eyepiece->now();
        });

        $this->eyepiece->listen(NotificationSent::class, function (NotificationSent $event): void {
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
                'duration' => $this->durationSince($started),
            ]);
        });
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    public function summarize(Capture $capture): Summary
    {
        $rows = $capture->rows('mail');

        if ($rows === []) {
            return Summary::make();
        }

        $byType = array_count_values(array_column($rows, 'type'));

        return Summary::make()
            ->total('mail_sent', $byType['mail'] ?? 0, 'mails sent')
            ->total('notifications_sent', $byType['notification'] ?? 0, 'notifications sent');
    }

    private function durationSince(?float $started): ?float
    {
        if ($started === null) {
            return null;
        }

        return round($this->eyepiece->now() - $started, 3);
    }

    private function mailKey(object $message): string
    {
        return 'mail:'.spl_object_id($message);
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

    public function presentation(): Presentation
    {
        return Presentation::table()
            ->tab('monocle::tabs.mail')
            ->groupBy(['type' => 'by type']);
    }
}
