<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\ClientPhoto;

/** Before/after/reference photo stored on the private disk (served only to authorised staff). */
class UploadClientPhoto
{
    public function handle(Client $client, string $path, string $kind = 'before', ?string $caption = null): ClientPhoto
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));

        $photo = $client->photos()->create([
            'tenant_id' => $client->tenant_id,
            'path' => $path,
            'kind' => in_array($kind, ['before', 'after', 'reference'], true) ? $kind : 'before',
            'caption' => $caption,
        ]);
        activity()->performedOn($client)->causedBy(auth()->user())->log('client.photo_added');

        return $photo;
    }
}
