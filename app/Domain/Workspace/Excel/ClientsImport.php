<?php

namespace App\Domain\Workspace\Excel;

use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\Tag;
use Illuminate\Support\Carbon;

/** Upserts clients by E.164 phone (unique per tenant). */
class ClientsImport extends WorkspaceImport
{
    public static function columns(): array
    {
        return ['name', 'phone', 'email', 'gender', 'birthday', 'source', 'tags', 'notes'];
    }

    public static function example(): array
    {
        return ['name' => 'Noor Al-Thani', 'phone' => '+97455123456', 'email' => 'noor@example.com', 'gender' => 'female',
            'birthday' => '1992-04-18', 'source' => 'instagram', 'tags' => 'VIP, Balayage', 'notes' => 'Prefers morning appointments'];
    }

    public static function exportRows(): array
    {
        return Client::query()->with('tags')->orderBy('name')->get()->map(fn (Client $c) => [
            'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'gender' => $c->gender,
            'birthday' => $c->birthday?->toDateString(), 'source' => $c->source, 'tags' => $c->tags->pluck('name')->join(', '), 'notes' => $c->notes,
        ])->all();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'regex:/^\+?[0-9 ()\-]{7,20}$/'],
            'email' => ['nullable', 'email'],
            'gender' => ['nullable', 'in:female,male,other'],
            'birthday' => ['nullable', 'date'],
        ];
    }

    protected function importRow(array $row): string
    {
        $phone = Client::normalizePhone((string) $row['phone']);
        $client = Client::withTrashed()->firstOrNew(['phone' => $phone]);
        $exists = $client->exists;

        $client->fill(array_filter([
            'name' => $row['name'],
            'email' => $row['email'] ?? null,
            'gender' => $row['gender'] ?? null,
            'birthday' => filled($row['birthday'] ?? null) ? Carbon::parse($row['birthday'])->toDateString() : null,
            'source' => $row['source'] ?? ($exists ? null : 'import'),
            'notes' => $row['notes'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        $client->deleted_at = null;
        $client->save();

        if (filled($row['tags'] ?? null)) {
            $ids = collect(explode(',', (string) $row['tags']))->map(fn ($t) => trim($t))->filter()
                ->map(fn ($t) => Tag::firstOrCreate(['name' => $t])->id);
            $client->tags()->syncWithoutDetaching($ids->all());
        }

        return $exists ? 'updated' : 'created';
    }
}
