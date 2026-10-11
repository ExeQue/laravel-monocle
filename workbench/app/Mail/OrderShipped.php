<?php

declare(strict_types=1);

namespace Workbench\App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OrderShipped extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your order has shipped');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Your order is on its way.</p>');
    }
}
