<?php

namespace App\Filament\Pos\Pages;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Payments\Actions\GiftCardLedger;
use App\Domain\Payments\Models\Payment;
use App\Domain\POS\Actions\CompleteSale;
use App\Domain\POS\Actions\CreateSaleOnlinePayment;
use App\Domain\POS\Actions\OpenShift;
use App\Domain\POS\Actions\SendReceipt;
use App\Domain\POS\Actions\VerifyPosPin;
use App\Domain\POS\Models\Register;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\Shift;
use App\Domain\POS\Support\BookingTicket;
use App\Domain\POS\Support\PosApprovals;
use App\Domain\POS\Support\SellableRegistry;
use App\Domain\POS\Support\TicketCalculator;
use App\Domain\Team\Models\Staff;
use App\Support\Exceptions\DomainException;
use App\Support\Money\Money;
use App\Support\Pdf\PdfRenderer;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The checkout terminal (POS home). Tablet-first two-pane layout:
 * catalog on the start side, the ticket + payment on the end side.
 * All money in minor units; totals are recomputed server-side by CompleteSale.
 */
class Terminal extends Page
{
    protected static string $routePath = '/';

    protected static ?int $navigationSort = -2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected string $view = 'filament.pos.terminal';

    protected Width|string|null $maxContentWidth = Width::Full;

    // Register / shift
    public ?int $registerId = null;

    public string $openingFloat = '';

    // Catalog
    public string $tab = 'services';

    public string $search = '';

    public ?int $categoryId = null;

    // Ticket
    /** @var array<string, array<string, mixed>> */
    public array $lines = [];

    public ?int $clientId = null;

    public string $clientSearch = '';

    public string $newClientName = '';

    public ?int $bookingId = null;

    public int $deposit = 0;

    /** @var array{type: string, value: int}|null */
    public ?array $ticketDiscount = null;

    #[Locked]
    public ?int $discountApprovedBy = null;

    #[Locked]
    public ?string $discountApproval = null;

    /** @var array<int|string, string> staff_id => decimal */
    public array $tips = [];

    public string $notes = '';

    public string $idempotencyKey = '';

    // Payment
    /** @var array<int, array<string, mixed>> */
    public array $tenders = [];

    public string $tenderAmount = '';

    public string $tenderReference = '';

    public string $giftCardCode = '';

    public string $pointsToUse = '';

    public ?int $onlinePaymentId = null;

    // Modals: discount | pin | client | gift_card | bookings | receipt | switch | tips | shift
    public ?string $modal = null;

    public ?string $discountTarget = null;

    public string $discountType = 'percent';

    public string $discountInput = '';

    public string $pin = '';

    public ?string $pinPurpose = null;

    public string $giftAmount = '';

    public string $giftRecipient = '';

    public string $giftRecipientPhone = '';

    public ?int $lastSaleId = null;

    public string $receiptEmail = '';

    public static function getRoutePath(Panel $panel): string
    {
        return static::$routePath;
    }

    public static function canAccess(): bool
    {
        return (bool) tenant()?->hasModule('pos') && (auth()->user()?->can('pos.access') ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.nav.terminal');
    }

    public function getTitle(): string|Htmlable
    {
        return __('pos.nav.terminal');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function mount(): void
    {
        $this->idempotencyKey = (string) Str::ulid();
        $registers = $this->registers;
        $remembered = (int) session('pos.register_id');
        $this->registerId = $registers->firstWhere('id', $remembered)?->id ?? $registers->first()?->id;
        $this->openingFloat = Money::of((int) ($this->register?->default_float ?? 0), $this->currency())->toDecimal();
    }

    /** Actions mutate the ticket after totals were read; always render fresh numbers. */
    public function rendering(): void
    {
        unset($this->totals, $this->quickCash, $this->client, $this->onlinePayment);
    }

    // ───────────────────────────── Register & shift ─────────────────────────────

    #[Computed]
    public function registers(): Collection
    {
        return Register::query()->where('is_active', true)->with('branch')->orderBy('branch_id')->orderBy('name')->get();
    }

    #[Computed]
    public function register(): ?Register
    {
        return $this->registerId ? $this->registers->firstWhere('id', $this->registerId) : null;
    }

    #[Computed]
    public function branch(): ?Branch
    {
        return $this->register?->branch;
    }

    #[Computed]
    public function shift(): ?Shift
    {
        return $this->registerId ? Shift::where('pos_register_id', $this->registerId)->where('status', 'open')->latest('opened_at')->first() : null;
    }

    public function selectRegister(int $id): void
    {
        if ($this->registers->firstWhere('id', $id)) {
            $this->registerId = $id;
            session(['pos.register_id' => $id]);
            unset($this->register, $this->branch, $this->shift);
            $this->resetTicket();
        }
    }

    public function openShift(OpenShift $open): void
    {
        $this->attempt(function () use ($open) {
            $open->handle($this->register ?? throw new DomainException(__('pos.errors.no_register')), auth()->user(), $this->toMinor($this->openingFloat));
            unset($this->shift);
            Notification::make()->success()->title(__('pos.shift.opened'))->send();
        });
    }

    // ───────────────────────────── Catalog ─────────────────────────────

    #[Computed]
    public function tabs(): array
    {
        $tabs = ['services' => __('pos.tabs.services'), 'products' => __('pos.tabs.products'), 'gift_cards' => __('pos.tabs.gift_cards')];
        if (app(SellableRegistry::class)->hasCatalog('package')) {
            $tabs['packages'] = __('pos.tabs.packages'); // appears once the marketing module registers packages
        }

        return $tabs;
    }

    #[Computed]
    public function categories(): Collection
    {
        return match ($this->tab) {
            'services' => ServiceCategory::query()->orderBy('sort')->get(['id', 'name']),
            'products' => ProductCategory::query()->orderBy('sort')->get(['id', 'name']),
            default => collect(),
        };
    }

    /** @return array<int, array{type: string, id: int, name: string, price: int, meta: ?string, stock: ?int, color: ?string}> */
    #[Computed]
    public function tiles(): array
    {
        $branch = $this->branch;
        if (! $branch) {
            return [];
        }
        $term = trim($this->search);

        return match ($this->tab) {
            'services' => Service::query()->active()->with(['branches', 'category'])
                ->when($this->categoryId, fn ($q) => $q->where('service_category_id', $this->categoryId))
                ->when($term !== '', fn ($q) => $q->where('name', 'like', '%'.$term.'%'))
                ->orderBy('sort')->limit(120)->get()
                ->filter(fn (Service $s) => $s->isActiveAt($branch))
                ->map(fn (Service $s) => ['type' => 'service', 'id' => $s->id, 'name' => (string) $s->name, 'price' => $s->priceAt($branch),
                    'meta' => $s->duration_minutes.' '.__('salon.ui.minutes'), 'stock' => null, 'color' => null])
                ->values()->all(),
            'products' => ProductVariant::query()
                ->with(['product', 'stock' => fn ($q) => $q->withoutGlobalScopes()->where('branch_id', $branch->id)])
                ->whereHas('product', fn ($q) => $q->where('is_active', true)
                    ->when($this->categoryId, fn ($c) => $c->where('product_category_id', $this->categoryId)))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('product', fn ($p) => $p->where('name', 'like', '%'.$term.'%'))
                    ->orWhere('sku', $term)->orWhere('barcode', $term)))
                ->limit(120)->get()
                ->map(fn (ProductVariant $v) => ['type' => 'product', 'id' => $v->id, 'name' => $v->displayName(), 'price' => $v->effectivePrice(),
                    'meta' => $v->sku, 'stock' => max(0, (int) ($v->stock->first()?->qty ?? 0) - (int) ($v->stock->first()?->reserved ?? 0)), 'color' => $v->swatch])
                ->all(),
            'gift_cards' => collect([10000, 20000, 50000, 100000])->map(fn ($amount) => ['type' => 'gift_card', 'id' => $amount, 'name' => __('pos.gift_card'),
                'price' => $amount, 'meta' => null, 'stock' => null, 'color' => null])->all(),
            'packages' => collect(app(SellableRegistry::class)->catalog('package', $branch))->map(fn ($p) => ['type' => 'package', 'id' => (int) $p['id'],
                'name' => (string) $p['name'], 'price' => (int) $p['price'], 'meta' => $p['meta'] ?? null, 'stock' => null, 'color' => null])->all(),
            default => [],
        };
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, $this->tabs)) {
            $this->tab = $tab;
            $this->categoryId = null;
        }
    }

    /** Barcode scanners type the code and press Enter. */
    public function scan(): void
    {
        $code = trim($this->search);
        if ($code === '') {
            return;
        }
        $variant = ProductVariant::query()->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->first();
        if ($variant) {
            $this->addItem('product', $variant->id);
            $this->search = '';

            return;
        }
        $tiles = $this->tiles;
        if (count($tiles) === 1) {
            $this->addItem($tiles[0]['type'], $tiles[0]['id']);
            $this->search = '';
        }
    }

    public function addItem(string $type, int $id): void
    {
        if (! $this->branch) {
            return;
        }
        if ($type === 'gift_card') {
            $this->giftAmount = Money::of($id, $this->currency())->toDecimal();
            $this->modal = 'gift_card';

            return;
        }

        $this->attempt(function () use ($type, $id) {
            // Same product again → bump the quantity.
            if ($type === 'product' && ($uid = collect($this->lines)->search(fn ($l) => $l['type'] === 'product' && (int) $l['id'] === $id && ! $l['discount_type']))) {
                $this->lines[$uid]['qty']++;

                return;
            }
            $resolved = app(SellableRegistry::class)->resolve($type, $id, $this->branch);
            $this->pushLine(['type' => $type, 'id' => $id, 'name' => $resolved['name'], 'unit_price' => $resolved['unit_price'], 'tax_percent' => $resolved['tax_percent']]);
        });
    }

    public function addGiftCard(): void
    {
        $amount = $this->toMinor($this->giftAmount);
        if ($amount <= 0) {
            $this->notifyError(__('pos.errors.gift_card_amount'));

            return;
        }
        $this->pushLine(['type' => 'gift_card', 'id' => null, 'name' => __('pos.gift_card').' '.money($amount), 'unit_price' => $amount, 'tax_percent' => 0,
            'recipient' => array_filter(['name' => trim($this->giftRecipient), 'phone' => trim($this->giftRecipientPhone)])]);
        $this->reset('giftAmount', 'giftRecipient', 'giftRecipientPhone', 'modal');
    }

    protected function pushLine(array $line): string
    {
        $uid = 'l'.Str::lower(Str::random(8));
        $this->lines[$uid] = array_merge(['uid' => $uid, 'qty' => 1, 'staff_id' => null, 'discount_type' => null, 'discount_value' => 0,
            'booking_item_id' => null, 'recipient' => [], 'locked' => false, 'tax_percent' => null], $line);

        return $uid;
    }

    public function increment(string $uid): void
    {
        if (isset($this->lines[$uid]) && $this->lines[$uid]['type'] !== 'gift_card') {
            $this->lines[$uid]['qty']++;
        }
    }

    public function decrement(string $uid): void
    {
        if (! isset($this->lines[$uid])) {
            return;
        }
        $this->lines[$uid]['qty'] > 1 ? $this->lines[$uid]['qty']-- : $this->removeLine($uid);
    }

    public function removeLine(string $uid): void
    {
        unset($this->lines[$uid]);
        if (! $this->lines) {
            $this->ticketDiscount = null;
        }
    }

    #[Computed]
    public function staff(): Collection
    {
        return Staff::query()->where('is_active', true)
            ->when($this->branch, fn ($q) => $q->whereHas('branches', fn ($b) => $b->withoutGlobalScopes()->where('branches.id', $this->branch->id)))
            ->orderBy('sort')->get(['id', 'name', 'color']);
    }

    // ───────────────────────────── Client ─────────────────────────────

    #[Computed]
    public function client(): ?Client
    {
        return $this->clientId ? Client::find($this->clientId) : null;
    }

    #[Computed]
    public function clientResults(): Collection
    {
        $term = trim($this->clientSearch);
        if (mb_strlen($term) < 2) {
            return collect();
        }
        $digits = preg_replace('/\D/', '', $term);

        return Client::query()
            ->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')
                ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('phone', 'like', '%'.$digits.'%')))
            ->orderByDesc('last_visit_at')->limit(8)->get();
    }

    public function selectClient(int $id): void
    {
        if ($client = Client::find($id)) {
            $this->clientId = $client->id;
            $this->reset('clientSearch', 'newClientName', 'modal');
            unset($this->client);
        }
    }

    /** Quick-create by phone (the search box holds the number). */
    public function quickCreateClient(): void
    {
        $digits = preg_replace('/\D/', '', $this->clientSearch);
        if (strlen($digits) < 8) {
            $this->notifyError(__('pos.errors.phone_required'));

            return;
        }
        $phone = Client::normalizePhone($this->clientSearch);
        $client = Client::where('phone', $phone)->first() ?? Client::create([
            'name' => trim($this->newClientName) ?: __('pos.client.walk_in'), 'phone' => $phone, 'source' => 'walk_in',
            'preferred_branch_id' => $this->branch?->id,
        ]);
        $this->selectClient($client->id);
    }

    public function clearClient(): void
    {
        $this->clientId = null;
        $this->tenders = array_values(array_filter($this->tenders, fn ($t) => ! in_array($t['method'], ['wallet', 'points'], true)));
        unset($this->client);
    }

    // ───────────────────────────── Today's bookings ─────────────────────────────

    #[Computed]
    public function todaysBookings(): Collection
    {
        if (! $this->branch) {
            return collect();
        }
        $tz = $this->branch->timezone ?: config('salon.defaults.timezone');

        return Booking::query()->where('branch_id', $this->branch->id)->whereNull('sale_id')
            ->whereIn('status', [BookingStatus::CheckedIn->value, BookingStatus::InService->value, BookingStatus::Confirmed->value])
            ->whereBetween('starts_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()])
            ->with(['client', 'branch', 'items.service', 'items.staff'])->orderByRaw("CASE status WHEN 'checked_in' THEN 0 WHEN 'in_service' THEN 1 ELSE 2 END")
            ->orderBy('starts_at')->get();
    }

    public function loadBooking(int $id): void
    {
        $booking = Booking::where('branch_id', $this->branch?->id)->whereNull('sale_id')->find($id);
        if (! $booking) {
            $this->notifyError(__('pos.errors.booking_unavailable'));

            return;
        }
        $this->resetTicket();
        $ticket = BookingTicket::from($booking);
        $this->bookingId = $booking->id;
        $this->clientId = $ticket['client_id'];
        $this->deposit = $ticket['deposit'];
        foreach ($ticket['lines'] as $line) {
            $this->pushLine($line + ['locked' => true]);
        }
        $this->modal = null;
        unset($this->client);
    }

    // ───────────────────────────── Discounts (permission or manager PIN) ─────────────────────────────

    public function openDiscount(?string $uid = null): void
    {
        $this->discountTarget = $uid ?? 'ticket';
        $current = $uid ? ['type' => $this->lines[$uid]['discount_type'] ?? null, 'value' => $this->lines[$uid]['discount_value'] ?? 0] : $this->ticketDiscount;
        $this->discountType = $current['type'] ?? 'percent';
        $this->discountInput = ! empty($current['type']) ? ($current['type'] === 'percent' ? (string) ($current['value'] / 100) : Money::of((int) $current['value'], $this->currency())->toDecimal()) : '';
        $this->modal = 'discount';
    }

    public function applyDiscount(): void
    {
        if (! $this->canDiscount()) {
            $this->askPin('discount');

            return;
        }
        $value = $this->discountType === 'percent'
            ? (int) round(min(100, max(0, (float) str_replace(',', '.', $this->discountInput))) * 100)
            : $this->toMinor($this->discountInput);
        $discount = $value > 0 ? ['type' => $this->discountType, 'value' => $value] : null;

        if ($this->discountTarget === 'ticket') {
            $this->ticketDiscount = $discount;
        } elseif (isset($this->lines[$this->discountTarget])) {
            $this->lines[$this->discountTarget]['discount_type'] = $discount['type'] ?? null;
            $this->lines[$this->discountTarget]['discount_value'] = $discount['value'] ?? 0;
        }
        $this->reset('modal', 'discountTarget', 'discountInput');
    }

    public function canDiscount(): bool
    {
        return auth()->user()->can('pos.discount') || $this->discountApprovedBy !== null;
    }

    protected function askPin(string $purpose): void
    {
        $this->pinPurpose = $purpose;
        $this->pin = '';
        $this->modal = 'pin';
    }

    public function submitPin(VerifyPosPin $verify): void
    {
        $this->attempt(function () use ($verify) {
            if ($this->pinPurpose === 'switch') {
                $user = $verify->handle($this->pin, 'pos.access', forSwitching: true);
                Auth::guard('web')->login($user);
                session()->put('password_hash_web', $user->getAuthPassword());
                $this->discountApprovedBy = null;
                $this->discountApproval = null;
                $this->reset('pin', 'pinPurpose', 'modal');
                Notification::make()->success()->title(__('pos.switch.done', ['name' => $user->name]))->send();

                return;
            }

            $manager = $verify->handle($this->pin, 'pos.discount');
            $this->discountApprovedBy = $manager->id;
            $this->discountApproval = PosApprovals::issue($manager, auth()->user());
            $this->pin = '';
            $this->pinPurpose = null;
            Notification::make()->success()->title(__('pos.pin.approved', ['name' => $manager->name]))->send();
            $this->applyDiscount();
        });
    }

    public function switchUser(): void
    {
        $this->askPin('switch');
    }

    // ───────────────────────────── Tips ─────────────────────────────

    public function setTip(int $staffId, string $amount): void
    {
        $minor = $this->toMinor($amount);
        if ($minor > 0) {
            $this->tips[$staffId] = Money::of($minor, $this->currency())->toDecimal();
        } else {
            unset($this->tips[$staffId]);
        }
    }

    // ───────────────────────────── Totals ─────────────────────────────

    /** @return array{lines: array, subtotal: int, discount: int, tax: int, tips: int, total: int, deposit: int, due: int, paid: int, remaining: int, change: int} */
    #[Computed]
    public function totals(): array
    {
        $branch = $this->branch;
        $calc = TicketCalculator::calculate(array_values($this->lines), $this->ticketDiscount, $this->tipsMinor(), (float) ($branch?->tax_percent ?? 0), (bool) ($branch?->tax_inclusive ?? true));
        $calc['lines'] = array_combine(array_keys($this->lines), $calc['lines']) ?: [];
        $deposit = min($this->deposit, $calc['total']);
        $due = $calc['total'] - $deposit;
        $nonCash = (int) collect($this->tenders)->where('method', '!=', 'cash')->sum('amount');
        $cash = (int) collect($this->tenders)->where('method', 'cash')->sum('amount');
        $cashDue = max(0, $due - $nonCash);

        return $calc + [
            'deposit' => $deposit,
            'due' => $due,
            'paid' => $nonCash + min($cash, $cashDue) + $deposit,
            'remaining' => max(0, $cashDue - $cash),
            'change' => max(0, $cash - $cashDue),
        ];
    }

    protected function tipsMinor(): array
    {
        return collect($this->tips)->map(fn ($v) => $this->toMinor((string) $v))->filter()->all();
    }

    /** Quick tender suggestions: exact + the next round notes. */
    #[Computed]
    public function quickCash(): array
    {
        $remaining = $this->totals['remaining'];
        if ($remaining <= 0) {
            return [];
        }
        $out = [$remaining];
        foreach ([5000, 10000, 20000, 50000] as $note) {
            $rounded = (int) (ceil($remaining / $note) * $note);
            if ($rounded > $remaining && ! in_array($rounded, $out, true)) {
                $out[] = $rounded;
            }
        }

        return array_slice($out, 0, 4);
    }

    // ───────────────────────────── Payments ─────────────────────────────

    public function tenderCash(?int $amount = null): void
    {
        $amount ??= $this->tenderAmount !== '' ? $this->toMinor($this->tenderAmount) : $this->totals['remaining'];
        if ($amount <= 0) {
            return;
        }
        $this->tenders[] = ['method' => 'cash', 'amount' => $amount, 'label' => __('salon.payments.methods.cash')];
        $this->reset('tenderAmount');
    }

    public function tenderCard(): void
    {
        $amount = $this->tenderAmount !== '' ? $this->toMinor($this->tenderAmount) : $this->totals['remaining'];
        if ($amount <= 0) {
            return;
        }
        $this->tenders[] = ['method' => 'card_terminal', 'amount' => min($amount, $this->totals['remaining']), 'reference' => trim($this->tenderReference) ?: null, 'label' => __('salon.payments.methods.card_terminal')];
        $this->reset('tenderAmount', 'tenderReference');
    }

    public function tenderWallet(): void
    {
        $client = $this->client;
        if (! $client) {
            $this->notifyError(__('pos.errors.needs_client'));

            return;
        }
        $available = (int) $client->wallet_balance - (int) collect($this->tenders)->where('method', 'wallet')->sum('amount');
        $amount = min($this->tenderAmount !== '' ? $this->toMinor($this->tenderAmount) : $this->totals['remaining'], $available, $this->totals['remaining']);
        if ($amount <= 0) {
            $this->notifyError(__('salon.errors.insufficient_wallet', ['balance' => money($available)]));

            return;
        }
        $this->tenders[] = ['method' => 'wallet', 'amount' => $amount, 'label' => __('salon.payments.methods.wallet')];
        $this->reset('tenderAmount');
    }

    public function tenderGiftCard(GiftCardLedger $ledger): void
    {
        $this->attempt(function () use ($ledger) {
            $card = $ledger->findUsable($this->giftCardCode);
            $used = (int) collect($this->tenders)->where('code', $card->code)->sum('amount');
            $amount = min((int) $card->balance - $used, $this->tenderAmount !== '' ? $this->toMinor($this->tenderAmount) : $this->totals['remaining'], $this->totals['remaining']);
            if ($amount <= 0) {
                throw new DomainException(__('salon.errors.gift_card_balance', ['balance' => money((int) $card->balance - $used)]));
            }
            $this->tenders[] = ['method' => 'gift_card', 'amount' => $amount, 'code' => $card->code, 'label' => __('salon.payments.methods.gift_card').' ·'.substr($card->code, -4)];
            $this->reset('tenderAmount', 'giftCardCode');
        });
    }

    public function tenderPoints(LoyaltyLedger $loyalty): void
    {
        $client = $this->client;
        if (! $client) {
            $this->notifyError(__('pos.errors.needs_client'));

            return;
        }
        $already = (int) collect($this->tenders)->where('method', 'points')->sum('points');
        $maxPoints = min((int) $client->loyalty_points - $already, $loyalty->pointsFor($this->totals['remaining']));
        $points = $this->pointsToUse !== '' ? min((int) $this->pointsToUse, $maxPoints) : $maxPoints;
        $value = $loyalty->valueOf($points);
        if ($points <= 0 || $value <= 0) {
            $this->notifyError(__('salon.errors.insufficient_points', ['points' => $client->loyalty_points]));

            return;
        }
        if ($value > $this->totals['remaining']) { // never overpay with points
            $points = intdiv($this->totals['remaining'], max(1, $loyalty->valueOf(1)));
            $value = $loyalty->valueOf($points);
        }
        $this->tenders[] = ['method' => 'points', 'amount' => $value, 'points' => $points, 'label' => __('pos.pay.points_label', ['points' => $points])];
        $this->reset('pointsToUse');
    }

    public function startOnlinePayment(CreateSaleOnlinePayment $create): void
    {
        $this->attempt(function () use ($create) {
            $amount = $this->tenderAmount !== '' ? min($this->toMinor($this->tenderAmount), $this->totals['remaining']) : $this->totals['remaining'];
            $payment = $create->handle($this->idempotencyKey, $this->branch?->id ?? throw new DomainException(__('pos.errors.no_register')), $amount, $this->clientId);
            $this->onlinePaymentId = $payment->id;
            $this->reset('tenderAmount');
        });
    }

    #[Computed]
    public function onlinePayment(): ?Payment
    {
        return $this->onlinePaymentId ? Payment::find($this->onlinePaymentId) : null;
    }

    public function onlineQr(): ?string
    {
        $payment = $this->onlinePayment;

        return $payment ? PdfRenderer::qr((string) ($payment->checkout_url ?: ($payment->meta['qr'] ?? ''))) : null;
    }

    /** Polled every few seconds while a QR is displayed. */
    public function checkOnlinePayment(CreateSaleOnlinePayment $create): void
    {
        $payment = $this->onlinePayment;
        if (! $payment) {
            return;
        }
        $status = $create->status($payment);
        if ($status === 'succeeded') {
            $this->tenders[] = ['method' => 'online', 'amount' => (int) $payment->amount, 'payment_id' => $payment->id, 'label' => __('salon.payments.methods.online')];
            $this->onlinePaymentId = null;
            Notification::make()->success()->title(__('pos.pay.online_received'))->send();
        } elseif (in_array($status, ['failed', 'cancelled'], true)) {
            $this->onlinePaymentId = null;
            $this->notifyError(__('pos.pay.online_failed'));
        }
        unset($this->onlinePayment);
    }

    public function cancelOnlinePayment(): void
    {
        $this->onlinePaymentId = null;
    }

    public function removeTender(int $index): void
    {
        if (($this->tenders[$index]['method'] ?? null) === 'online') {
            return; // money already taken online — refund from the sale afterwards
        }
        unset($this->tenders[$index]);
        $this->tenders = array_values($this->tenders);
    }

    // ───────────────────────────── Complete ─────────────────────────────

    public function complete(CompleteSale $complete): void
    {
        if (! $this->lines) {
            $this->notifyError(__('pos.errors.empty_ticket'));

            return;
        }
        if (! $this->shift) {
            $this->notifyError(__('pos.errors.no_shift'));

            return;
        }
        if ($this->totals['remaining'] > 0) {
            $this->notifyError(__('pos.errors.underpaid', ['amount' => money($this->totals['remaining'])]));

            return;
        }

        $this->attempt(function () use ($complete) {
            $sale = $complete->handle($this->payload(), auth()->user());
            $this->lastSaleId = $sale->id;
            $this->receiptEmail = (string) ($sale->client?->email ?? '');
            $this->resetTicket();
            $this->modal = 'receipt';
            unset($this->shift);
        });
    }

    /** The exact payload CompleteSale (and the offline queue) receives. */
    public function payload(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'branch_id' => $this->branch?->id,
            'register_id' => $this->registerId,
            'shift_id' => $this->shift?->id,
            'client_id' => $this->clientId,
            'booking_id' => $this->bookingId,
            'lines' => collect($this->lines)->map(fn ($l) => collect($l)->only(['type', 'id', 'qty', 'unit_price', 'discount_type', 'discount_value', 'staff_id', 'booking_item_id', 'recipient'])->all())->values()->all(),
            'ticket_discount' => $this->ticketDiscount,
            'discount_approval' => $this->discountApproval,
            'tips' => $this->tipsMinor(),
            'payments' => collect($this->tenders)->map(fn ($t) => match ($t['method']) {
                'cash' => ['method' => 'cash', 'tendered' => $t['amount']],
                'points' => ['method' => 'points', 'points' => $t['points']],
                'gift_card' => ['method' => 'gift_card', 'code' => $t['code'], 'amount' => $t['amount']],
                'online' => ['method' => 'online', 'payment_id' => $t['payment_id']],
                default => ['method' => $t['method'], 'amount' => $t['amount'], 'reference' => $t['reference'] ?? null],
            })->all(),
            'notes' => $this->notes ?: null,
        ];
    }

    #[Computed]
    public function lastSale(): ?Sale
    {
        return $this->lastSaleId ? Sale::with(['client', 'salePayments'])->find($this->lastSaleId) : null;
    }

    public function sendReceipt(string $channel, SendReceipt $send): void
    {
        $sale = $this->lastSale;
        if (! $sale) {
            return;
        }
        $this->attempt(function () use ($channel, $send, $sale) {
            $to = $channel === 'email' ? $this->receiptEmail : (string) $sale->client?->phone;
            $result = $send->handle($sale, $channel, $to);
            $result->ok
                ? Notification::make()->success()->title(__('pos.receipt.sent'))->send()
                : $this->notifyError($result->error ?? __('pos.receipt.failed'));
        });
    }

    public function whatsappLink(): ?string
    {
        return $this->lastSale ? app(SendReceipt::class)->whatsappLink($this->lastSale) : null;
    }

    public function newTicket(): void
    {
        $this->resetTicket();
        $this->modal = null;
    }

    protected function resetTicket(): void
    {
        $this->reset('lines', 'clientId', 'clientSearch', 'bookingId', 'deposit', 'ticketDiscount', 'discountApprovedBy', 'discountApproval', 'tips', 'notes',
            'tenders', 'tenderAmount', 'tenderReference', 'giftCardCode', 'pointsToUse', 'onlinePaymentId');
        $this->idempotencyKey = (string) Str::ulid();
        unset($this->client, $this->totals);
    }

    public function closeModal(): void
    {
        $this->reset('modal', 'pin', 'pinPurpose');
    }

    public function openModal(string $modal): void
    {
        if (in_array($modal, ['client', 'bookings', 'tips', 'gift_card', 'notes'], true)) {
            $this->modal = $modal;
        }
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    public function currency(): string
    {
        return tenant()?->currency ?? config('salon.defaults.currency', 'QAR');
    }

    protected function toMinor(string $decimal): int
    {
        $clean = str_replace([',', ' '], ['.', ''], trim($decimal));

        return is_numeric($clean) ? max(0, Money::fromDecimal($clean, $this->currency())->amount) : 0;
    }

    protected function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException $e) {
            $this->notifyError($e->getMessage());
        }
    }

    protected function notifyError(string $message): void
    {
        Notification::make()->danger()->title($message)->send();
    }
}
