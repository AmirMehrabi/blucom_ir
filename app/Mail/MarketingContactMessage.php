<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MarketingContactMessage extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array{name: string, email?: string|null, phone?: string|null, topic?: string|null, message: string} $submission */
    public function __construct(public array $submission) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'پیام جدید از فرم تماس بلوکام',
            replyTo: filled($this->submission['email'] ?? null)
                ? [$this->submission['email']]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marketing.contact-message',
        );
    }
}
