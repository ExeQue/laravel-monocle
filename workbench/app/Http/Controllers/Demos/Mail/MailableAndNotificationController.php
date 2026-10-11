<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Demos\Mail;

use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Workbench\App\Http\Controllers\Demos\Concerns\RendersResult;
use Workbench\App\Mail\OrderShipped;
use Workbench\App\Models\User;
use Workbench\App\Notifications\WelcomeNotification;

final class MailableAndNotificationController
{
    use RendersResult;

    public function __invoke(): View
    {
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        Mail::to($user)->send(new OrderShipped());
        $user->notify(new WelcomeNotification());

        return $this->result('Mail and notifications', 'Sent to the log mailer.');
    }
}
