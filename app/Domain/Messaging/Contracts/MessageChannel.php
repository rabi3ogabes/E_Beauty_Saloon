<?php

namespace App\Domain\Messaging\Contracts;

use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\DTOs\SendResult;

/**
 * Strategy interface for messaging providers (Meta WhatsApp Cloud, Twilio/Unifonic SMS, SMTP/SES, FCM push...).
 * The Marketing module registers real drivers; core ships log/mail/fake drivers.
 */
interface MessageChannel
{
    /** whatsapp|sms|email|push */
    public function channel(): string;

    public function send(OutboundMessage $message): SendResult;
}
