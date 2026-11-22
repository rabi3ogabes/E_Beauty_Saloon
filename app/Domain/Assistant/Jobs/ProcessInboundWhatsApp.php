<?php

namespace App\Domain\Assistant\Jobs;

use App\Domain\Assistant\Actions\HandleInboundMessage;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Handles one inbound WhatsApp message for the AI receptionist inside the tenant context. */
class ProcessInboundWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $tenantId, public string $from, public ?string $text, public ?string $messageId, public ?string $profileName = null) {}

    public function handle(TenantContext $context): void
    {
        if (! $tenant = Tenant::find($this->tenantId)) {
            return;
        }
        $context->run($tenant, fn () => app(HandleInboundMessage::class)->handle($this->from, $this->text, $this->messageId, $this->profileName));
    }
}
