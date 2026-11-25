<?php

namespace App\Mail;

use App\Models\NotificationLog;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ExpiryDigest extends Mailable
{
    public function __construct(public NotificationLog $log) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->log->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.expiry', text: 'mail.expiry-text', with: [
            'log' => $this->log,
            'items' => $this->log->items ?? [],
            'url' => rtrim((string) config('app.url'), '/'),
        ]);
    }
}
