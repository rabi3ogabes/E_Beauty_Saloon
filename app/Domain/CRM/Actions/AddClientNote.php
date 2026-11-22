<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\ClientNote;

class AddClientNote
{
    public function handle(Client $client, string $body, bool $private = true): ClientNote
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));

        $note = $client->notesTimeline()->create([
            'tenant_id' => $client->tenant_id, 'user_id' => auth()->id(), 'body' => trim($body), 'is_private' => $private,
        ]);
        activity()->performedOn($client)->causedBy(auth()->user())->log('client.note_added');

        return $note;
    }
}
