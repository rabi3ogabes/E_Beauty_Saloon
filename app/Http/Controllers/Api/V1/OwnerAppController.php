<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Owner\OwnerAlerts;
use App\Domain\Api\Owner\TodayKpis;
use App\Domain\Branches\Models\Branch;
use App\Domain\Marketing\Actions\EstimateCampaignCost;
use App\Domain\Marketing\Actions\LaunchCampaign;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\Segment;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TeamMemberResource;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Owner app
 *
 * Today's KPIs per branch, alerts and quick campaigns. Branch restrictions of the member apply; KPIs need
 * reports.view (revenue figures reports.revenue), campaigns marketing.send.
 */
class OwnerAppController extends Controller
{
    /** Quick-campaign audiences offered by the owner app → SegmentQuery conditions. */
    public const AUDIENCES = [
        'all' => [],
        'lapsed' => [['field' => 'not_visited_days', 'value' => 60]],
        'vip' => [['field' => 'vip', 'value' => true]],
        'birthday' => [['field' => 'birthday_this_month', 'value' => true]],
    ];

    /** Me. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => (new TeamMemberResource($this->user($request)))->resolve($request)]);
    }

    /**
     * Today's KPIs.
     *
     * @queryParam branch_id integer Omit for all branches the member can see. Example: 1
     * @queryParam date string Local date (default today). Example: 2026-11-22
     */
    public function kpis(Request $request, TodayKpis $kpis): JsonResponse
    {
        $request->validate(['branch_id' => ['nullable', 'integer'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $user = $this->user($request);
        abort_unless($user->can('reports.view'), 403);
        $branch = $request->filled('branch_id') ? Branch::query()->findOrFail($request->integer('branch_id')) : null; // BranchScope = member restrictions

        $k = $kpis->handle($branch, $request->query('date'));
        $currency = tenant()->currency ?? 'QAR';
        $money = fn (int $v) => $user->can('reports.revenue') ? Money::of($v, $currency) : null;

        return response()->json(['data' => [
            'date' => $k['date'],
            'timezone' => $k['timezone'],
            'branch_id' => $k['branch_id'],
            'revenue_today' => $money($k['revenue_today']),
            'revenue_yesterday' => $money($k['revenue_yesterday']),
            'revenue_breakdown' => $user->can('reports.revenue') ? array_map(fn ($v) => Money::of($v, $currency), $k['revenue_breakdown']) : null,
            'average_ticket' => $money($k['average_ticket']),
            'sales_count' => $k['sales_count'],
            'hourly_revenue' => $user->can('reports.revenue') ? $k['hourly_revenue'] : [],
            'bookings_today' => $k['bookings_today'],
            'bookings_completed' => $k['bookings_completed'],
            'no_shows' => $k['no_shows'],
            'cancellations' => $k['cancellations'],
            'utilization' => $k['utilization'],
            'new_clients' => $k['new_clients'],
        ]]);
    }

    /**
     * Alerts.
     *
     * @queryParam branch_id integer Example: 1
     */
    public function alerts(Request $request, OwnerAlerts $alerts): JsonResponse
    {
        $request->validate(['branch_id' => ['nullable', 'integer']]);
        $branchId = $request->filled('branch_id') ? Branch::query()->findOrFail($request->integer('branch_id'))->id : null;

        return response()->json(['data' => $alerts->handle($this->user($request), $branchId)]);
    }

    /** Saved audiences for quick campaigns. */
    public function segments(Request $request): JsonResponse
    {
        abort_unless($this->user($request)->can('marketing.send'), 403);

        return response()->json(['data' => Segment::query()->orderBy('name')->get()->map(fn (Segment $s) => [
            'id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'clients' => (int) $s->cached_count, 'counted_at' => $s->counted_at?->toIso8601ZuluString(),
        ])->values()]);
    }

    /**
     * Quick campaign.
     *
     * Sends a short message now to a saved segment through the Marketing module (consent, quiet hours and message
     * credits apply exactly as for dashboard campaigns). Send an Idempotency-Key.
     *
     * @header Idempotency-Key 7c3e1a9b-5d2f-4e8a-b6c4-0f1e2d3c4b5a
     *
     * @bodyParam segment_id integer required Saved segment (`audience` is accepted as an alias). Example: 3
     * @bodyParam channel string required whatsapp|sms|email|push. Example: whatsapp
     * @bodyParam message string required Body; placeholders {first_name} {salon} {link}. Example: Hi {first_name}, 20% off blow-dries today: {link}
     * @bodyParam subject string Email subject / push title.
     */
    public function campaign(Request $request, LaunchCampaign $launch, EstimateCampaignCost $estimate): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->can('marketing.send'), 403);
        if (! $request->filled('segment_id') && is_numeric($request->input('audience'))) {
            $request->merge(['segment_id' => (int) $request->input('audience'), 'audience' => null]);
        }
        $data = $request->validate([
            'segment_id' => ['required_without:audience', 'nullable', 'integer'],
            'audience' => ['required_without:segment_id', 'nullable', 'in:'.implode(',', array_keys(self::AUDIENCES))],
            'channel' => ['required_without:channels', 'in:whatsapp,sms,email,push'],
            'channels' => ['nullable', 'array', 'min:1'],
            'channels.*' => ['in:whatsapp,sms,email,push'],
            'message' => ['required', 'string', 'max:1000'],
            'subject' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        $segment = ! empty($data['segment_id']) ? Segment::query()->findOrFail($data['segment_id']) : $this->presetSegment($data['audience']);
        $locale = app()->getLocale();
        $content = ['en' => ['subject' => $data['subject'] ?? null, 'body' => $data['message']]];
        if ($locale !== 'en') {
            $content[$locale] = $content['en'];
        }

        $campaign = DB::transaction(fn () => Campaign::create([
            'name' => $data['name'] ?? __('api.owner.quick_campaign', ['date' => now(tenant()->timezone)->format('Y-m-d H:i')]),
            'segment_id' => $segment->id,
            'channels' => $data['channels'] ?? [$data['channel']],
            'content' => $content,
        ]));
        activity()->performedOn($campaign)->causedBy($user)->withProperties(['source' => 'owner_app'])->log('campaign.quick_created');

        $cost = $estimate->handle($campaign);
        try {
            $campaign = $launch->handle($campaign);
        } catch (\Throwable $e) {
            $campaign->delete(); // nothing was sent: don't leave an orphan draft behind
            throw $e;
        }

        return response()->json(['data' => [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'recipients' => (int) $campaign->recipients_count,
            'estimated_cost' => Money::of((int) ($cost['cost'] ?? 0), tenant()->currency ?? 'QAR'),
        ], 'message' => __('api.owner.campaign_queued', ['count' => (int) $campaign->recipients_count])], 201);
    }

    /** Saved segment behind an app preset audience (created once per salon, visible in the dashboard). */
    protected function presetSegment(string $audience): Segment
    {
        return Segment::query()->firstOrCreate(['description' => 'owner-app:'.$audience], [
            'name' => __('api.owner.audiences.'.$audience),
            'rules' => ['match' => 'all', 'conditions' => self::AUDIENCES[$audience]],
        ]);
    }

    protected function user(Request $request): User
    {
        return $request->user();
    }
}
