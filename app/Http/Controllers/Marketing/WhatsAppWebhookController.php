<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\Marketing\Actions\ProcessWhatsAppWebhook;
use App\Domain\Marketing\Models\PlatformMessageChannel;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WhatsAppWebhookController extends Controller
{
    /** Meta subscription handshake: hub.mode=subscribe&hub.verify_token=...&hub.challenge=... */
    public function verify(Request $request)
    {
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $valid = $token !== '' && (
            hash_equals((string) PlatformMessageChannel::where('channel', 'whatsapp')->first()?->credential('verify_token'), $token)
            || TenantChannelSetting::withoutGlobalScopes()->where('channel', 'whatsapp')->get()->contains(fn ($s) => hash_equals((string) $s->credential('verify_token'), $token))
        );
        abort_unless($valid && $request->query('hub_mode', $request->query('hub.mode')) === 'subscribe', 403);

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge')), 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request, ProcessWhatsAppWebhook $processor)
    {
        $raw = $request->getContent();
        $signer = $processor->verifiedSigner($raw, $request->header('X-Hub-Signature-256'));
        abort_unless($signer !== null, 401, 'Invalid signature');

        return response()->json($processor->handle(json_decode($raw, true) ?: [], $signer));
    }
}
