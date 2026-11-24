<?php

namespace App\Support\Monitoring;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

class ErrorAlertMail extends Mailable
{
    public function __construct(public ErrorContext $context) {}

    public function envelope(): Envelope
    {
        $name = config('salon.name');

        return new Envelope(subject: "[{$name}] Error: ".Str::limit(class_basename($this->context->class).' — '.$this->context->message, 110));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.error-alert', with: ['c' => $this->context]);
    }
}
