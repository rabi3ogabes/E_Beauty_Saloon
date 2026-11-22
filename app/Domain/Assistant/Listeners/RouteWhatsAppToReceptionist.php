<?php

namespace App\Domain\Assistant\Listeners;

use App\Domain\Assistant\Jobs\ProcessInboundWhatsApp;
use App\Domain\Marketing\Events\WhatsAppMessageReceived;

/** Marketing's inbound WhatsApp hook → queued receptionist job (logged even when the AI is off, for the inbox). */
class RouteWhatsAppToReceptionist
{
    public function handle(WhatsAppMessageReceived $event): void
    {
        ProcessInboundWhatsApp::dispatch($event->tenantId, $event->from, $event->text, $event->messageId, $event->profileName);
    }
}
