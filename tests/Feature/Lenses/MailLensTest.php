<?php

declare(strict_types=1);

namespace Tests\Feature\Lenses;

use ExeQue\Monocle\Enums\CaptureType;
use ExeQue\Monocle\Eyepiece;
use ExeQue\Monocle\Lenses\MailLens;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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
        expect(app(MailLens::class)->summarize(app(Eyepiece::class)->finalize()))->toEqual(new \ExeQue\Monocle\Data\Summary());
    });
});
