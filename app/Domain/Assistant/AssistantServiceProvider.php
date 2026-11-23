<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\AssistantModel;
use App\Domain\Assistant\Drivers\RouterAssistantModel;
use App\Domain\Assistant\Listeners\RouteWhatsAppToReceptionist;
use App\Domain\Assistant\Models\Conversation;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Events\WhatsAppMessageReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Phase 9 — AI assistants: WhatsApp receptionist (tool calling over the AI router) and content assistant. */
class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(AssistantModel::class, RouterAssistantModel::class);
    }

    public function boot(): void
    {
        Event::listen(WhatsAppMessageReceived::class, RouteWhatsAppToReceptionist::class);
        Client::resolveRelationUsing('assistantConversations', fn (Client $c) => $c->hasMany(Conversation::class));
    }
}
