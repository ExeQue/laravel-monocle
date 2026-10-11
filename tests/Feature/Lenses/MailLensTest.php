<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\MailLens;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\Fixtures\WelcomeNotification;

covers(MailLens::class);

describe(MailLens::class, function (): void {
    beforeEach(function (): void {
        config(['mail.default' => 'array', 'mail.from.address' => 'noreply@example.com', 'mail.from.name' => 'App']);
        app(Eyepiece::class)->start(CaptureType::Request);
    });

    it('records sent mail with addresses, size and duration', function (): void {
        Mail::raw('Hello body', function ($message): void {
            $message->to('ada@example.com')->cc('bob@example.com')->subject('Greetings');
        });

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('mail');

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['type' => 'mail', 'subject' => 'Greetings', 'to' => ['ada@example.com'], 'cc' => ['bob@example.com'], 'bcc' => [], 'attachments' => 0])
            ->and($rows[0]['from'][0])->toContain('noreply@example.com')
            ->and($rows[0]['body_bytes'])->toBe(10)
            ->and($rows[0]['duration'])->toBeNumeric()
            ->and($rows[0]['origin']['file'])->toContain('MailLensTest.php');
    });

    it('records notifications and the mail they produce', function (): void {
        Notification::route('mail', 'ada@example.com')->notify(new WelcomeNotification());

        $capture = app(Eyepiece::class)->finalize();
        $rows = $capture->rows('mail');
        $types = array_column($rows, 'type');

        expect($types)->toContain('mail', 'notification')
            ->and(collect($rows)->firstWhere('type', 'notification'))->toMatchArray(['notification' => WelcomeNotification::class, 'channel' => 'mail'])
            ->and(collect($rows)->firstWhere('type', 'notification')['notifiable'])->toContain('AnonymousNotifiable')
            ->and(collect($rows)->firstWhere('type', 'mail')['subject'])->toBe('Welcome aboard');

        $summary = app(MailLens::class)->summarize($capture);

        expect($summary->totals)->toBe(['mail_sent' => 1, 'notifications_sent' => 1]);
    });

    it('summarizes nothing without mail', function (): void {
        expect(app(MailLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(\ExeQue\Monocle\Data\Summary::make());
    });

    it('ignores mail and notifications sent while not recording', function (): void {
        app(Eyepiece::class)->discard();

        Mail::raw('Hello body', function ($message): void {
            $message->to('ada@example.com')->subject('Greetings');
        });
        Notification::route('mail', 'ada@example.com')->notify(new WelcomeNotification());

        app(Eyepiece::class)->start(CaptureType::Request);

        expect(app(Eyepiece::class)->finalize()->rows('mail'))->toBe([]);
    });

    it('skips sent messages that are not emails', function (): void {
        $envelope = new Envelope(new Address('noreply@example.com'), [new Address('ada@example.com')]);

        event(new MessageSent(new SentMessage(new SymfonySentMessage(new RawMessage('raw'), $envelope))));

        expect(app(Eyepiece::class)->finalize()->rows('mail'))->toBe([]);
    });

    it('records no duration for a mail sent without a sending event', function (): void {
        $email = new Email()->from('noreply@example.com')->to('ada@example.com')->subject('Direct')->text('Hi');
        $envelope = new Envelope(new Address('noreply@example.com'), [new Address('ada@example.com')]);

        event(new MessageSent(new SentMessage(new SymfonySentMessage($email, $envelope))));

        $rows = app(Eyepiece::class)->finalize()->rows('mail');

        expect($rows[0])->toMatchArray(['subject' => 'Direct', 'mailable' => null, 'duration' => null]);
    });

    it('falls back to the object id when reading the notification id throws', function (): void {
        $notification = new class () {
            public function __isset(string $name): bool
            {
                return true;
            }

            public function __get(string $name): mixed
            {
                throw new RuntimeException('no id');
            }
        };
        $notifiable = new AnonymousNotifiable();

        event(new NotificationSending($notifiable, $notification, 'mail'));
        event(new NotificationSent($notifiable, $notification, 'mail'));

        $rows = app(Eyepiece::class)->finalize()->rows('mail');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['channel'])->toBe('mail')
            ->and($rows[0]['duration'])->toBeNumeric();
    });

    it('presents mail as a table grouped by type', function (): void {
        $presentation = app(MailLens::class)->presentation();

        expect($presentation->tabView)->toBe('monocle::tabs.mail')
            ->and($presentation->groupings)->toBe(['type' => 'by type']);
    });
});
