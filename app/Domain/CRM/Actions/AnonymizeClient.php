<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Right to erasure: removes personal data but keeps financial records (anonymised) for accounting. */
class AnonymizeClient
{
    public function handle(Client $client): void
    {
        DB::transaction(function () use ($client) {
            foreach ($client->photos as $photo) {
                Storage::disk('local')->delete($photo->path);
            }
            $client->photos()->delete();
            $client->notesTimeline()->delete();
            $client->consents()->update(['granted' => false]);
            $client->tags()->detach();
            $client->tokens()->delete();
            $client->forceFill([
                'name' => 'Deleted client',
                'phone' => '+0'.$client->id.'-'.substr(md5((string) $client->id), 0, 6),
                'email' => null, 'birthday' => null, 'notes' => null, 'allergies' => null, 'password' => null, 'is_blocked' => true,
            ])->save();
            $client->delete();
            activity()->performedOn($client)->log('client.anonymized');
        });
    }
}
