<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CRM\Actions\AnonymizeClient;
use App\Domain\CRM\Actions\ExportClientData;
use App\Domain\CRM\Models\Client;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ClientResource;
use App\Support\Money\Money;
use Illuminate\Http\Request;

/**
 * @group Profile, wallet & loyalty
 *
 * @authenticated
 */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return new ClientResource($request->user());
    }

    public function update(Request $request)
    {
        $client = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'], 'email' => ['sometimes', 'nullable', 'email'], 'birthday' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'in:female,male'], 'locale' => ['sometimes', 'in:en,ar'], 'consents' => ['sometimes', 'array'], 'consents.*' => ['boolean'],
        ]);
        $client->update(collect($data)->except('consents')->all());
        foreach ($data['consents'] ?? [] as $channel => $granted) {
            if (in_array($channel, Client::CHANNELS, true)) {
                $client->setConsent($channel, (bool) $granted, 'app', $request->ip());
            }
        }

        return new ClientResource($client->fresh());
    }

    public function wallet(Request $request)
    {
        $client = $request->user();
        $currency = tenant()->currency;

        return response()->json([
            'balance' => Money::of((int) $client->wallet_balance, $currency),
            'transactions' => $client->walletTransactions()->limit(50)->get()->map(fn ($t) => [
                'id' => $t->id, 'type' => $t->type, 'amount' => Money::of($t->amount, $currency), 'balance_after' => Money::of($t->balance_after, $currency),
                'note' => $t->note, 'created_at' => $t->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function loyalty(Request $request)
    {
        $client = $request->user();

        return response()->json([
            'points' => $client->loyalty_points,
            'tier' => $client->loyalty_tier,
            'referral_code' => $client->referral_code,
            'entries' => $client->loyaltyEntries()->limit(50)->get()->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type, 'points' => $e->points, 'balance_after' => $e->balance_after, 'note' => $e->note, 'created_at' => $e->created_at->toIso8601String(),
            ]),
        ]);
    }

    /** GDPR / Qatar PDPPL: export my data. */
    public function export(Request $request)
    {
        return response()->json(app(ExportClientData::class)->handle($request->user()));
    }

    /** Delete my account (anonymises personal data, keeps financial records). */
    public function destroy(Request $request)
    {
        app(AnonymizeClient::class)->handle($request->user());

        return response()->noContent();
    }
}
