<?php

namespace App\Domain\Marketing\Channels;

use App\Domain\Messaging\Contracts\MessageChannel;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Email with the salon's own from / reply-to, through any configured Laravel mailer
 * (smtp, ses, mailgun... — "mailer" credential). Uses the shared luxury layout mail.luxe.
 */
class TenantMailChannel implements MessageChannel
{
    public function __construct(protected array $config = []) {}

    public function channel(): string
    {
        return 'email';
    }

    public function send(OutboundMessage $message): SendResult
    {
        $mailer = $this->config['mailer'] ?? null;
        if ($mailer && ! config("mail.mailers.$mailer")) {
            $mailer = null;
        }

        try {
            Mail::mailer($mailer)->send('mail.luxe', ['body' => $message->body, 'buttons' => $message->buttons, 'mediaUrl' => $message->mediaUrl, 'tenant' => tenant()],
                function (Message $m) use ($message) {
                    $m->to($message->to)->subject($message->subject ?? tenant()?->name ?? config('salon.name'));
                    if (! empty($this->config['from_address'])) {
                        $m->from($this->config['from_address'], $this->config['from_name'] ?? tenant()?->name);
                    }
                    $replyTo = $this->config['reply_to'] ?? tenant()?->email;
                    if ($replyTo) {
                        $m->replyTo($replyTo, tenant()?->name);
                    }
                    if (! empty($message->meta['unsubscribe_url'])) {
                        $m->getSymfonyMessage()->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$message->meta['unsubscribe_url'].'>');
                    }
                });

            return new SendResult(true);
        } catch (\Throwable $e) {
            return new SendResult(false, null, $e->getMessage());
        }
    }
}
