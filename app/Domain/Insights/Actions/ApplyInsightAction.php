<?php

namespace App\Domain\Insights\Actions;

use App\Domain\Insights\Models\InsightCard;
use App\Domain\Inventory\Actions\CreatePurchaseOrder;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\Segment;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One-click actions on insight cards. Everything created is a DRAFT the owner reviews:
 *   campaign_draft        → marketing campaign (status draft) + an opted-in segment
 *   purchase_order_draft  → one draft purchase order per branch (CreatePurchaseOrder)
 *   pricing_review        → records the owner's approval; prices are never changed automatically
 * Idempotent: a card can only be acted on once.
 */
class ApplyInsightAction
{
    public function __construct(protected CreatePurchaseOrder $createPurchaseOrder) {}

    public function handle(InsightCard $card): InsightCard
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        if (! $card->isOpen()) {
            return $card;
        }

        return DB::transaction(function () use ($card) {
            $params = (array) ($card->action['params'] ?? []);
            $result = match ($card->actionType()) {
                'campaign_draft' => ['campaign_id' => $this->campaignDraft($params)->id],
                'purchase_order_draft' => ['purchase_order_ids' => $this->purchaseOrders($params)],
                'pricing_review' => ['approved' => true],
                default => throw new DomainException(__('insights.cards.no_action')),
            };
            $card->update([
                'status' => $card->actionType() === 'pricing_review' ? 'approved' : 'done',
                'result' => $result, 'acted_by' => auth()->id(), 'acted_at' => now(),
            ]);
            activity()->performedOn($card)->causedBy(auth()->user())->withProperties($result)->log('insights.card_actioned');

            return $card;
        });
    }

    public function dismiss(InsightCard $card): InsightCard
    {
        if ($card->isOpen()) {
            $card->update(['status' => 'dismissed', 'acted_by' => auth()->id(), 'acted_at' => now()]);
        }

        return $card;
    }

    public function campaignDraft(array $params): Campaign
    {
        $kind = $params['kind'] ?? 'happy_hour';
        $segment = $kind === 'win_back'
            ? Segment::firstOrCreate(['name' => __('insights.drafts.win_back_segment', ['days' => $params['days'] ?? 45])], [
                'rules' => ['match' => 'all', 'conditions' => [['field' => 'not_visited_days', 'value' => (int) ($params['days'] ?? 45)], ['field' => 'has_consent', 'value' => 'whatsapp']]],
            ])
            : Segment::firstOrCreate(['name' => __('insights.drafts.opted_in_segment')], [
                'rules' => ['match' => 'all', 'conditions' => [['field' => 'has_consent', 'value' => 'whatsapp']]],
            ]);
        $segment->refreshCount();

        $vars = [];
        foreach (['en', 'ar'] as $locale) {
            $vars[$locale] = [
                'day' => isset($params['weekday']) ? CarbonImmutable::now()->startOfWeek(CarbonImmutable::SUNDAY)->addDays((int) $params['weekday'])->locale($locale)->dayName : '',
                'from' => sprintf('%02d:00', (int) ($params['from'] ?? 14)),
                'to' => sprintf('%02d:00', (int) ($params['to'] ?? 17)),
                'discount' => (int) ($params['discount'] ?? 15),
                'salon' => tenant()->name,
            ];
        }
        $content = [];
        foreach (['en', 'ar'] as $locale) {
            $content[$locale] = [
                'subject' => __('insights.drafts.'.$kind.'.subject', $vars[$locale], $locale),
                'body' => __('insights.drafts.'.$kind.'.body', $vars[$locale], $locale),
            ];
        }

        return Campaign::create([
            'name' => __('insights.drafts.'.$kind.'.name', $vars[app()->getLocale() === 'ar' ? 'ar' : 'en']),
            'segment_id' => $segment->id,
            'status' => 'draft',
            'channels' => ['whatsapp', 'sms'],
            'content' => $content,
            'cta_url' => '/book',
            'created_by' => auth()->id(),
        ]);
    }

    /** @return list<int> */
    protected function purchaseOrders(array $params): array
    {
        $ids = [];
        foreach (collect($params['items'] ?? [])->groupBy('branch_id') as $branchId => $items) {
            $ids[] = $this->createPurchaseOrder->handle((int) $branchId, null, $items->map(fn ($i) => [
                'product_variant_id' => (int) $i['product_variant_id'], 'qty' => (int) $i['qty'], 'unit_cost' => (int) ($i['unit_cost'] ?? 0),
            ])->all(), notes: __('insights.drafts.po_note'))->id;
        }

        return $ids;
    }
}
