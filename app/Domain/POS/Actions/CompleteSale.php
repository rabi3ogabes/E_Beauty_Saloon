<?php

namespace App\Domain\POS\Actions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\BookingStatusHistory;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Payments\Actions\GiftCardLedger;
use App\Domain\Payments\Actions\IssueGiftCard;
use App\Domain\Payments\Actions\WalletLedger;
use App\Domain\Payments\Models\Payment;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\Shift;
use App\Domain\POS\Support\PosApprovals;
use App\Domain\POS\Support\SellableRegistry;
use App\Domain\POS\Support\TicketCalculator;
use App\Domain\Team\Models\CommissionRule;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Completes a POS ticket in ONE transaction, idempotent by `idempotency_key`:
 * sale + items + payments, stock (sale), gift cards, booking → completed, commissions,
 * loyalty points, client stats, activity log. Replaying the same key returns the existing sale.
 *
 * Payload: see docs in tests/Feature/Pos/CheckoutTest.php — keys: idempotency_key, branch_id, register_id, shift_id,
 * client_id, booking_id, lines[], ticket_discount, discount_approved_by, tips{staff_id: minor}, payments[], notes,
 * offline_created_at.
 */
class CompleteSale
{
    public const PAYMENT_METHODS = ['cash', 'card_terminal', 'online', 'wallet', 'gift_card', 'points'];

    public const OFFLINE_METHODS = ['cash', 'card_terminal'];

    public function __construct(
        protected SellableRegistry $sellables,
        protected StockLedger $stock,
        protected WalletLedger $wallet,
        protected GiftCardLedger $giftCards,
        protected LoyaltyLedger $loyalty,
        protected IssueGiftCard $issueGiftCard,
        protected CalculateCommission $commissions,
    ) {}

    public function handle(array $payload, User $cashier, bool $offline = false): Sale
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        $key = (string) ($payload['idempotency_key'] ?? throw new DomainException(__('pos.errors.missing_key')));

        $existing = Sale::withoutGlobalScopes()->where('tenant_id', tenant()->id)->where('idempotency_key', $key)->first();
        if ($existing && $existing->status !== SaleStatus::Draft) {
            return $existing; // replay: never twice
        }
        if (empty($payload['lines'])) {
            throw new DomainException(__('pos.errors.empty_ticket'));
        }

        return DB::transaction(function () use ($payload, $cashier, $offline, $key) {
            $sale = Sale::withoutGlobalScopes()->where('tenant_id', tenant()->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($sale && $sale->status !== SaleStatus::Draft) {
                return $sale;
            }

            $branch = Branch::withoutGlobalScopes()->where('tenant_id', tenant()->id)->findOrFail((int) $payload['branch_id']);
            $client = ! empty($payload['client_id']) ? Client::findOrFail((int) $payload['client_id']) : null;
            $booking = ! empty($payload['booking_id']) ? Booking::withoutGlobalScopes()->where('tenant_id', tenant()->id)->with('items')->findOrFail((int) $payload['booking_id']) : null;
            $client ??= $booking?->client_id ? Client::find($booking->client_id) : null;
            $shift = ! empty($payload['shift_id']) ? Shift::withoutGlobalScopes()->where('tenant_id', tenant()->id)->find((int) $payload['shift_id']) : null;
            if ($shift && ! $offline && ! $shift->isOpen()) {
                throw new DomainException(__('pos.errors.shift_closed'));
            }
            $createdAt = ! empty($payload['offline_created_at']) ? CarbonImmutable::parse($payload['offline_created_at'])->utc() : CarbonImmutable::now('UTC');
            if ($createdAt->isFuture()) {
                $createdAt = CarbonImmutable::now();
            }

            // 1. Resolve lines at server prices.
            $lines = [];
            foreach (array_values($payload['lines']) as $line) {
                $lines[] = $this->resolveLine($line, $branch, $booking);
            }
            $tips = collect($payload['tips'] ?? [])->map(fn ($v) => max(0, (int) $v))->filter()->all();
            $ticketDiscount = ! empty($payload['ticket_discount']['type']) && (int) ($payload['ticket_discount']['value'] ?? 0) > 0 ? $payload['ticket_discount'] : null;
            $calc = TicketCalculator::calculate($lines, $ticketDiscount, $tips, (float) $branch->tax_percent, (bool) $branch->tax_inclusive);

            // 2. Discount authorization (pos.discount or a manager PIN approval).
            $approverId = null;
            if ($calc['discount'] > 0) {
                $approverId = $this->authorizeDiscount($cashier, $payload['discount_approval'] ?? null);
            }

            // 3. Payments.
            $deposit = $booking && ! $booking->sale_id ? min((int) $booking->amount_paid, $calc['total']) : 0;
            $due = $calc['total'] - $deposit;
            $payments = $this->normalizePayments($payload['payments'] ?? [], $due, $offline, $client, $sale);

            // 4. Sale header.
            $sale ??= new Sale(['idempotency_key' => $key]);
            $sale->forceFill([
                'tenant_id' => tenant()->id,
                'branch_id' => $branch->id,
                'pos_register_id' => $payload['register_id'] ?? $shift?->pos_register_id,
                'pos_shift_id' => $shift?->id,
                'client_id' => $client?->id,
                'booking_id' => $booking?->id,
                'user_id' => $cashier->id,
                'status' => SaleStatus::Completed,
                'subtotal' => $calc['subtotal'],
                'discount' => $calc['discount'],
                'tax' => $calc['tax'],
                'tips' => $calc['tips'],
                'total' => $calc['total'],
                'paid' => $deposit + $payments['applied'],
                'change_due' => $payments['change'],
                'currency' => tenant()->currency ?? 'QAR',
                'tax_percent' => (float) $branch->tax_percent,
                'tax_inclusive' => (bool) $branch->tax_inclusive,
                'discount_meta' => $ticketDiscount || $approverId ? ['ticket' => $ticketDiscount, 'approved_by' => $approverId] : null,
                'tips_meta' => collect($tips)->map(fn ($amount, $staff) => ['staff_id' => (int) $staff, 'amount' => $amount])->values()->all() ?: null,
                'is_offline' => $offline,
                'offline_created_at' => $offline ? $createdAt : null,
                'notes' => $payload['notes'] ?? null,
                'completed_at' => $createdAt,
            ]);
            $this->assignNumber($sale, $branch, $createdAt);
            $sale->save();

            // 5. Items, stock, gift cards.
            $conflicts = [];
            $items = [];
            foreach ($lines as $i => $line) {
                $c = $calc['lines'][$i];
                $item = $sale->items()->create([
                    'type' => $line['type'],
                    'sellable_type' => $line['model']?->getMorphClass(),
                    'sellable_id' => $line['model']?->getKey(),
                    'name' => mb_substr($line['name'], 0, 250),
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount' => $c['discount'],
                    'tax' => $c['tax'],
                    'total' => $c['total'],
                    'tax_percent' => $c['tax_percent'],
                    'staff_id' => $line['staff_id'],
                    'booking_item_id' => $line['booking_item_id'],
                    'meta' => array_filter(['recipient' => $line['recipient'] ?? null, 'line_discount' => $line['discount_type'] ? ['type' => $line['discount_type'], 'value' => $line['discount_value']] : null]) ?: null,
                ]);
                $items[] = $item;

                if ($line['type'] === 'product' && ($line['track_stock'] ?? true)) {
                    $movement = $this->stock->move($branch->id, $line['model']->getKey(), -$line['qty'], 'sale', $sale, "sale:{$sale->id}:item:{$item->id}", null, allowNegative: $offline);
                    if ($movement->balance_after < 0) {
                        $conflicts[] = ['product_variant_id' => $line['model']->getKey(), 'name' => $line['name'], 'qty' => $line['qty'], 'balance_after' => $movement->balance_after];
                    }
                }
                if (! in_array($line['type'], ['service', 'product', 'gift_card'], true)) {
                    $this->sellables->sold($line['type'], $item, $sale, $client);
                }
                if ($line['type'] === 'gift_card') {
                    $card = $this->issueGiftCard->handle($line['unit_price'], $line['recipient'] ?? [], $client?->id);
                    $item->update(['sellable_type' => $card->getMorphClass(), 'sellable_id' => $card->id, 'meta' => array_merge($item->meta ?? [], ['gift_card_code' => $card->code])]);
                }
            }

            // 6. Money.
            $this->recordPayments($sale, $payments['rows'], $client, $branch);
            if ($deposit > 0) {
                $this->recordDeposit($sale, $booking, $deposit);
            }

            // 7. Booking → completed.
            if ($booking) {
                $this->completeBooking($booking, $sale, $cashier);
            }

            // 8. Commissions.
            $rules = CommissionRule::withoutGlobalScopes()->where('tenant_id', tenant()->id)->get();
            foreach ($items as $item) {
                $this->commissions->handle($sale, $item, $rules);
            }

            // 9. Loyalty + client stats.
            if ($client) {
                $pointsPaid = (int) collect($payments['rows'])->where('method', 'points')->sum('amount');
                $spend = $calc['total'] - $calc['tips'] - $pointsPaid;
                if (tenant()->hasModule('loyalty') && $spend > 0) {
                    $this->loyalty->earnForSpend($client, $spend, $sale, "sale-earn:{$sale->id}");
                }
                $locked = Client::withoutGlobalScopes()->lockForUpdate()->find($client->id);
                $locked->forceFill([
                    'visits_count' => $locked->visits_count + 1,
                    'lifetime_value' => $locked->lifetime_value + $calc['total'] - $calc['tips'],
                    'last_visit_at' => $locked->last_visit_at && $locked->last_visit_at->greaterThan($createdAt) ? $locked->last_visit_at : $createdAt,
                ])->save();
            }

            if ($conflicts) {
                $sale->update(['sync_conflicts' => $conflicts]);
            }

            activity()->performedOn($sale)->causedBy($cashier)->withProperties([
                'number' => $sale->number, 'total' => $sale->total, 'branch_id' => $branch->id, 'offline' => $offline,
                'methods' => collect($payments['rows'])->pluck('method')->unique()->values()->all(), 'discount_approved_by' => $approverId,
            ])->log('pos.sale.completed');

            return $sale->fresh(['items', 'salePayments']);
        });
    }

    /** Resolve one ticket line to a sellable at the server price. */
    protected function resolveLine(array $line, Branch $branch, ?Booking $booking): array
    {
        $type = (string) ($line['type'] ?? '');
        $qty = max(1, (int) ($line['qty'] ?? 1));
        $staffId = ! empty($line['staff_id']) ? (int) $line['staff_id'] : null;
        $bookingItemId = ! empty($line['booking_item_id']) ? (int) $line['booking_item_id'] : null;

        if ($type === 'gift_card') {
            $amount = (int) ($line['unit_price'] ?? 0);
            if ($amount <= 0) {
                throw new DomainException(__('pos.errors.gift_card_amount'));
            }

            return ['type' => 'gift_card', 'model' => null, 'name' => __('pos.gift_card').' '.money($amount), 'qty' => 1, 'unit_price' => $amount,
                'tax_percent' => 0.0, 'staff_id' => $staffId, 'booking_item_id' => null, 'discount_type' => null, 'discount_value' => 0,
                'recipient' => array_filter((array) ($line['recipient'] ?? []))];
        }

        $resolved = $this->sellables->resolve($type, (int) ($line['id'] ?? 0), $branch, $staffId);
        $unit = $resolved['unit_price'];
        if ($bookingItemId && $booking && ($bi = $booking->items->firstWhere('id', $bookingItemId))) {
            $unit = (int) $bi->price; // the price the client booked
            $staffId ??= $bi->staff_id;
        }

        return [
            'type' => $type,
            'model' => $resolved['model'],
            'name' => $resolved['name'],
            'qty' => $qty,
            'unit_price' => $unit,
            'tax_percent' => $resolved['tax_percent'],
            'track_stock' => $resolved['track_stock'] ?? true,
            'staff_id' => $staffId,
            'booking_item_id' => $bookingItemId,
            'discount_type' => in_array($line['discount_type'] ?? null, ['percent', 'fixed'], true) && (int) ($line['discount_value'] ?? 0) > 0 ? $line['discount_type'] : null,
            'discount_value' => (int) ($line['discount_value'] ?? 0),
        ];
    }

    protected function authorizeDiscount(User $cashier, ?string $approvalToken): int
    {
        if ($cashier->can('pos.discount')) {
            return $cashier->id;
        }
        // Only a server-issued, single-use approval token (from a manager PIN) is accepted.
        $approverId = PosApprovals::consume($approvalToken, $cashier);
        $approver = $approverId ? User::where('tenant_id', tenant()->id)->find($approverId) : null;
        if ($approver && $approver->can('pos.discount')) {
            return $approver->id;
        }

        throw new DomainException(__('pos.errors.discount_needs_approval'));
    }

    /**
     * Validate tendered payments against the amount due. Cash may exceed (change); others may not.
     *
     * @return array{rows: array<int, array<string, mixed>>, applied: int, change: int}
     */
    protected function normalizePayments(array $input, int $due, bool $offline, ?Client $client, ?Sale $draft): array
    {
        $rows = [];
        $nonCash = 0;
        $cashTendered = 0;
        foreach (array_values($input) as $p) {
            $method = (string) ($p['method'] ?? '');
            if (! in_array($method, self::PAYMENT_METHODS, true)) {
                throw new DomainException(__('pos.errors.unknown_method'));
            }
            if ($offline && ! in_array($method, self::OFFLINE_METHODS, true)) {
                throw new DomainException(__('pos.errors.offline_method'));
            }
            $row = ['method' => $method, 'amount' => max(0, (int) ($p['amount'] ?? 0)), 'reference' => $p['reference'] ?? null];

            switch ($method) {
                case 'cash':
                    $row['tendered'] = max(0, (int) ($p['tendered'] ?? $p['amount'] ?? 0));
                    $cashTendered += $row['tendered'];
                    break;
                case 'online':
                    $payment = Payment::where('id', (int) ($p['payment_id'] ?? 0))->where('payable_type', (new Sale)->getMorphClass())
                        ->where('payable_id', $draft?->id ?? 0)->first();
                    if (! $payment || ! $payment->isSucceeded()) {
                        throw new DomainException(__('pos.errors.online_not_paid'));
                    }
                    $row['amount'] = (int) $payment->amount;
                    $row['payment'] = $payment;
                    break;
                case 'wallet':
                    $client ?? throw new DomainException(__('pos.errors.needs_client'));
                    break;
                case 'gift_card':
                    $row['card'] = $this->giftCards->findUsable((string) ($p['code'] ?? ''));
                    $row['reference'] = $row['card']->code;
                    break;
                case 'points':
                    $client ?? throw new DomainException(__('pos.errors.needs_client'));
                    $row['points'] = max(0, (int) ($p['points'] ?? 0));
                    $row['amount'] = $this->loyalty->valueOf($row['points']);
                    break;
            }
            if ($method !== 'cash') {
                if ($row['amount'] <= 0) {
                    continue;
                }
                $nonCash += $row['amount'];
            }
            $rows[] = $row;
        }

        if ($nonCash > $due) {
            throw new DomainException(__('pos.errors.overpaid'));
        }
        $cashDue = $due - $nonCash;
        if ($cashTendered < $cashDue) {
            throw new DomainException(__('pos.errors.underpaid', ['amount' => money($cashDue - $cashTendered)]));
        }

        // Apply cash in order; the surplus is change.
        $remaining = $cashDue;
        foreach ($rows as &$row) {
            if ($row['method'] === 'cash') {
                $row['amount'] = min($row['tendered'], $remaining);
                $remaining -= $row['amount'];
                $row['change'] = $row['tendered'] - $row['amount'];
            }
        }
        unset($row);
        $rows = array_values(array_filter($rows, fn ($r) => $r['method'] !== 'cash' || $r['amount'] > 0 || $r['change'] > 0));

        return ['rows' => $rows, 'applied' => $due, 'change' => $cashTendered - $cashDue];
    }

    protected function recordPayments(Sale $sale, array $rows, ?Client $client, Branch $branch): void
    {
        foreach ($rows as $i => $row) {
            $key = "sale:{$sale->id}:pay:{$i}";
            $payment = $row['payment'] ?? null;

            if (! $payment && $row['amount'] > 0) {
                $meta = [];
                if ($row['method'] === 'wallet') {
                    $this->wallet->debit($client, $row['amount'], 'spend', $sale, $key);
                } elseif ($row['method'] === 'gift_card') {
                    $this->giftCards->redeem($row['card'], $row['amount'], $sale, $key);
                    $meta['gift_card_id'] = $row['card']->id;
                } elseif ($row['method'] === 'points') {
                    $this->loyalty->redeem($client, $row['points'], $sale, $key);
                    $meta['points'] = $row['points'];
                } elseif ($row['method'] === 'cash') {
                    $meta = ['tendered' => $row['tendered'], 'change' => $row['change']];
                }

                $payment = Payment::create([
                    'branch_id' => $branch->id,
                    'client_id' => $client?->id,
                    'payable_type' => $sale->getMorphClass(),
                    'payable_id' => $sale->id,
                    'method' => $row['method'],
                    'driver' => in_array($row['method'], ['cash', 'card_terminal'], true) ? $row['method'] : null,
                    'purpose' => 'payment',
                    'status' => 'succeeded',
                    'amount' => $row['amount'],
                    'currency' => $sale->currency,
                    'provider_reference' => $row['reference'],
                    'idempotency_key' => $key,
                    'paid_at' => $sale->completed_at,
                    'meta' => $meta ?: null,
                ]);
            } elseif ($payment && ! $payment->client_id && $client) {
                $payment->update(['client_id' => $client->id]);
            }

            $sale->salePayments()->create([
                'payment_id' => $payment?->id,
                'method' => $row['method'],
                'amount' => $row['amount'],
                'tendered' => $row['tendered'] ?? null,
                'change' => $row['change'] ?? 0,
                'reference' => $row['reference'],
            ]);
        }
    }

    /** The booking deposit (already collected online) becomes a paid line on the sale. */
    protected function recordDeposit(Sale $sale, Booking $booking, int $deposit): void
    {
        $remaining = $deposit;
        $paid = Payment::withoutGlobalScopes()->where('payable_type', $booking->getMorphClass())->where('payable_id', $booking->id)
            ->whereIn('status', ['succeeded', 'partially_refunded'])->where('amount', '>', 0)->orderBy('id')->get();
        foreach ($paid as $payment) {
            if ($remaining <= 0) {
                break;
            }
            $amount = min($remaining, $payment->refundable());
            if ($amount <= 0) {
                continue;
            }
            $sale->salePayments()->create(['payment_id' => $payment->id, 'method' => 'deposit', 'amount' => $amount, 'reference' => $payment->provider_reference]);
            $remaining -= $amount;
        }
        if ($remaining > 0) {
            $sale->salePayments()->create(['payment_id' => null, 'method' => 'deposit', 'amount' => $remaining, 'reference' => $booking->reference]);
        }
    }

    protected function completeBooking(Booking $booking, Sale $sale, User $cashier): void
    {
        $from = $booking->status;
        $updates = ['sale_id' => $sale->id];
        if ($from !== BookingStatus::Completed && $from->canTransitionTo(BookingStatus::Completed)) {
            $updates += ['status' => BookingStatus::Completed, 'completed_at' => $sale->completed_at];
        }
        $booking->forceFill($updates)->save();
        if (isset($updates['status'])) {
            BookingStatusHistory::create([
                'booking_id' => $booking->id, 'from_status' => $from, 'to_status' => BookingStatus::Completed,
                'user_id' => $cashier->id, 'note' => __('pos.booking_completed_note', ['number' => $sale->number]),
            ]);
        }
    }

    /** Human number per branch per business day: WB-20261122-0007. */
    protected function assignNumber(Sale $sale, Branch $branch, CarbonImmutable $at): void
    {
        $date = $at->setTimezone($branch->timezone ?: config('salon.defaults.timezone'));
        $seq = (int) Sale::withoutGlobalScopes()->where('branch_id', $branch->id)->whereDate('business_date', $date->toDateString())
            ->lockForUpdate()->max('sequence') + 1;
        $prefix = collect(explode('-', (string) $branch->slug))->map(fn ($p) => strtoupper(mb_substr($p, 0, 1)))->take(3)->implode('') ?: 'POS';
        $sale->business_date = $date->toDateString();
        $sale->sequence = $seq;
        $sale->number = $prefix.'-'.$date->format('Ymd').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
