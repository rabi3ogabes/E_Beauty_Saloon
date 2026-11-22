<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\Marketing\Actions\TrackCampaignClick;
use App\Domain\Marketing\Actions\Unsubscribe;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\TenantChannelSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CampaignLinkController extends Controller
{
    /** /c/{token} → record click → /book?campaign={id} (or the campaign's CTA URL). */
    public function __invoke(string $token, TrackCampaignClick $track)
    {
        $message = CampaignMessage::with('campaign')->where('token', $token)->first();
        if (! $message) {
            return redirect('/');
        }

        return redirect($track->handle($message));
    }

    public function unsubscribe(string $token)
    {
        $message = CampaignMessage::with('client')->where('token', $token)->firstOrFail();

        return view('marketing.site.unsubscribe', ['message' => $message, 'done' => false]);
    }

    public function confirmUnsubscribe(Request $request, string $token, Unsubscribe $unsubscribe)
    {
        $message = CampaignMessage::with('client')->where('token', $token)->firstOrFail();
        $channels = $request->input('scope') === 'channel' && $message->channel ? [$message->channel] : null;
        if ($message->client) {
            $unsubscribe->handle($message->client, $channels, 'unsubscribe_link', $request->ip());
        }

        return view('marketing.site.unsubscribe', ['message' => $message, 'done' => true]);
    }

    /** Inbound SMS from a gateway: from / text (or From / Body for Twilio). Secret = sms setting credential "inbound_secret". */
    public function inboundSms(Request $request, string $secret, Unsubscribe $unsubscribe)
    {
        $expected = TenantChannelSetting::where('channel', 'sms')->first()?->credential('inbound_secret');
        abort_unless($expected && hash_equals((string) $expected, $secret), 403);
        $from = (string) ($request->input('from') ?? $request->input('From') ?? '');
        $text = (string) ($request->input('text') ?? $request->input('Body') ?? $request->input('message') ?? '');

        return response()->json(['unsubscribed' => $from !== '' && $unsubscribe->fromInbound($from, $text, 'sms')]);
    }
}
