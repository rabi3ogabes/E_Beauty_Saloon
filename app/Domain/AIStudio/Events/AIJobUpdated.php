<?php

namespace App\Domain\AIStudio\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Status change of an AI job. Broadcast-ready (Reverb/Pusher) — the studio UI also polls. */
class AIJobUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $tenantId, public int $jobId, public string $status) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantId.'.ai-jobs.'.$this->jobId)];
    }

    public function broadcastAs(): string
    {
        return 'ai.job.updated';
    }
}
