<?php

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/** Email through Laravel's mailer (SMTP / SES / Mailgun configured in .env), luxury HTML layout. */
class MailChannel implements MessageChannel
{
    public function channel(): string
    {
        return 'email';
    }

    public function send(OutboundMessage $message): SendResult
    {
        try {
            Mail::send('mail.luxe', ['body' => $message->body, 'buttons' => $message->buttons, 'mediaUrl' => $message->mediaUrl, 'tenant' => tenant()],
                function (Message $m) use ($message) {
                    $m->to($message->to)->subject($message->subject ?? tenant()?->name ?? config('salon.name'));
                    if (tenant()?->email) {
                        $m->replyTo(tenant()->email, tenant()->name);
                    }
                });

            return new SendResult(true);
        } catch (\Throwable $e) {
            return new SendResult(false, null, $e->getMessage());
        }
    }
}
