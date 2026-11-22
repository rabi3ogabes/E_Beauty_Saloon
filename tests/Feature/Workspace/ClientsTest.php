<?php

require_once __DIR__.'/WorkspaceHelpers.php';

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Actions\AddClientNote;
use App\Domain\CRM\Actions\AdjustClientPoints;
use App\Domain\CRM\Actions\SaveClientConsents;
use App\Domain\CRM\Actions\TopUpClientWallet;
use App\Domain\CRM\Actions\UploadClientPhoto;
use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Support\ClientTimeline;
use App\Filament\Dashboard\Resources\Clients\Pages\CreateClient;
use App\Filament\Dashboard\Resources\Clients\Pages\EditClient;
use App\Filament\Dashboard\Resources\Clients\Pages\ViewClient;
use App\Support\Exceptions\DomainException;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('creates a client with an E.164 phone unique per tenant and timestamped consents', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);

    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Noor Al-Thani', 'phone' => '5512 3456', 'email' => 'noor@example.com', 'source' => 'instagram', 'consent_whatsapp' => true, 'consent_sms' => false])
        ->call('create')->assertHasNoFormErrors();

    $client = Client::firstOrFail();
    expect($client->phone)->toBe('+97455123456')
        ->and($client->hasConsent('whatsapp'))->toBeTrue()->and($client->hasConsent('sms'))->toBeFalse()
        ->and($client->consents()->where('channel', 'whatsapp')->first()->created_at)->not->toBeNull();

    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Duplicate', 'phone' => '+974 5512 3456'])
        ->call('create')->assertHasFormErrors(['phone']);
});

it('shows allergies only to staff with clients.private_notes', function () {
    $tenant = createTenant();
    $client = asTenant($tenant, fn () => Client::create(['name' => 'Sara', 'phone' => '55000002', 'allergies' => 'Allergic to PPD hair dye']));
    $receptionist = tenantUser($tenant, 'receptionist', [asTenant($tenant, fn () => ws_main_branch()->id)]);

    $this->actingAs($tenant->owner)->get(tenantUrl($tenant, 'dashboard/clients/'.$client->id))->assertOk()->assertSee('Allergic to PPD hair dye');
    $this->flushSession();
    $this->actingAs($receptionist)->get(tenantUrl($tenant, 'dashboard/clients/'.$client->id))->assertOk()->assertDontSee('Allergic to PPD hair dye');
    $this->flushSession();
    $this->actingAs($receptionist)->get(tenantUrl($tenant, 'dashboard/clients/'.$client->id.'/edit'))->assertOk()->assertDontSee('Allergic to PPD hair dye');

    // Raw DB value is encrypted too.
    expect(DB::table('clients')->where('id', $client->id)->value('allergies'))->not->toContain('PPD');
});

it('keeps allergies when a receptionist edits the client', function () {
    $tenant = createTenant();
    $client = asTenant($tenant, fn () => Client::create(['name' => 'Sara', 'phone' => '55000003', 'allergies' => 'Latex']));
    $receptionist = tenantUser($tenant, 'receptionist', [asTenant($tenant, fn () => ws_main_branch()->id)]);
    Filament::setCurrentPanel('dashboard');
    asTenant($tenant);
    $this->actingAs($receptionist);

    Livewire::test(EditClient::class, ['record' => $client->id])->fillForm(['name' => 'Sara M.'])->call('save')->assertHasNoFormErrors();
    expect($client->fresh()->name)->toBe('Sara M.')->and($client->fresh()->allergies)->toBe('Latex');
});

it('adds notes, tops up the wallet and adjusts points from the client profile', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $client = Client::create(['name' => 'Huda', 'phone' => '55000004']);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction('addNote', data: ['body' => 'Prefers Lina', 'is_private' => true])->assertHasNoActionErrors()
        ->callAction('topUp', data: ['amount' => '500', 'type' => 'topup', 'note' => 'Gift'])->assertHasNoActionErrors()
        ->callAction('adjustPoints', data: ['points' => 250, 'reason' => 'Welcome bonus'])->assertHasNoActionErrors();

    $client->refresh();
    expect($client->wallet_balance)->toBe(50000)->and($client->loyalty_points)->toBe(250)
        ->and($client->notesTimeline()->first()->body)->toBe('Prefers Lina')
        ->and($client->walletTransactions()->first()->type)->toBe('topup')
        ->and($client->loyaltyEntries()->first()->type)->toBe('adjustment');
});

it('streams private client photos only to the same salon team', function () {
    Storage::fake('local');
    $a = createTenant();
    $b = createTenant();
    $photo = asTenant($a, function () use ($a) {
        $client = Client::create(['name' => 'Huda', 'phone' => '55000009']);
        $path = UploadedFile::fake()->image('after.jpg')->store('client-photos/'.$a->id, 'local');

        return app(UploadClientPhoto::class)->handle($client, $path, 'after');
    });

    $this->actingAs($a->owner)->get(tenantUrl($a, 'dashboard/media/client-photos/'.$photo->id))->assertOk();
    $this->flushSession();
    $this->actingAs($b->owner)->get(tenantUrl($b, 'dashboard/media/client-photos/'.$photo->id))->assertNotFound();
});

it('covers the CRM actions: negative points, wallet validation, consents and photos', function () {
    Storage::fake('local');
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $client = Client::create(['name' => 'Huda', 'phone' => '55000005']);

    app(AdjustClientPoints::class)->handle($client, 100, 'Bonus');
    $entry = app(AdjustClientPoints::class)->handle($client, -40, 'Correction');
    expect($entry->points)->toBe(-40)->and($client->fresh()->loyalty_points)->toBe(60);
    expect(fn () => app(AdjustClientPoints::class)->handle($client, -500, 'Too much'))->toThrow(DomainException::class);
    expect(fn () => app(TopUpClientWallet::class)->handle($client, 0))->toThrow(DomainException::class);

    $tx = app(TopUpClientWallet::class)->handle($client, 1000, 'store_credit', null, 'key-1');
    expect(app(TopUpClientWallet::class)->handle($client, 1000, 'store_credit', null, 'key-1')->id)->toBe($tx->id)
        ->and($client->fresh()->wallet_balance)->toBe(1000);

    app(SaveClientConsents::class)->handle($client, ['email' => true, 'push' => false, 'fax' => true]);
    expect($client->fresh()->hasConsent('email'))->toBeTrue()->and($client->consents()->count())->toBe(2);

    $path = UploadedFile::fake()->image('before.jpg')->store('client-photos/'.$tenant->id, 'local');
    $photo = app(UploadClientPhoto::class)->handle($client, $path, 'after', 'Balayage result');
    expect($photo->kind)->toBe('after');

    app(AddClientNote::class)->handle($client, 'Private remark', true);
    app(AddClientNote::class)->handle($client, 'Public remark', false);
    Booking::create(['branch_id' => ws_main_branch()->id, 'client_id' => $client->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(), 'total' => 18000, 'status' => 'completed']);

    $types = ClientTimeline::for($client)->pluck('type')->unique()->values()->all();
    expect($types)->toContain('booking', 'wallet', 'loyalty', 'note', 'photo');

    $receptionist = tenantUser($tenant, 'receptionist', [ws_main_branch()->id]);
    asTenant($tenant);
    $this->actingAs($receptionist);
    $notes = ClientTimeline::for($client)->where('type', 'note')->pluck('body')->all();
    expect($notes)->toBe(['Public remark']);
});
