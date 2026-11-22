<?php

namespace App\Filament\Dashboard\Pages;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Module;
use App\Domain\Billing\Models\Plan;
use App\Domain\Branches\Actions\CreateBranch;
use App\Domain\Workspace\Actions\PreviewPlanChange;
use App\Domain\Workspace\Actions\RequestPlanChange;
use App\Domain\Workspace\Actions\StartPlatformCheckout;
use App\Domain\Workspace\Actions\SubmitBankTransferProof;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

/**
 * Owner billing at /dashboard/billing: plan, modules, branches, renewal, invoices (PDF), pay an open
 * invoice (bank-transfer proof or platform checkout) and change plan with a prorated preview.
 * Its route is named tenant.billing.show so it stays reachable while the dashboard is locked.
 */
class Billing extends Page implements HasTable
{
    use InteractsWithTable;

    public const ROUTE_NAME = 'tenant.billing.show';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'billing';

    protected string $view = 'filament.dashboard.pages.billing';

    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        $route = Route::get(static::getRoutePath($panel), static::class)
            ->middleware(static::getRouteMiddleware($panel))
            ->withoutMiddleware(static::getWithoutRouteMiddleware($panel));
        $route->setAction(array_merge($route->getAction(), ['as' => self::ROUTE_NAME]));
        app('router')->getRoutes()->refreshNameLookups();
    }

    public static function getRouteName(?Panel $panel = null): string
    {
        return self::ROUTE_NAME;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('workspace.billing.title');
    }

    public function getTitle(): string
    {
        return __('workspace.billing.title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('settings.billing');
    }

    public function getSubscription()
    {
        return tenant()->currentSubscription?->loadMissing('plan') ?? tenant()->subscriptions()->with('plan')->latest('id')->first();
    }

    public function getOnlineDrivers(): array
    {
        return StartPlatformCheckout::availableDrivers();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Invoice::query()->where('tenant_id', tenant()->id)->withCount(['payments as pending_proofs' => fn ($q) => $q->where('status', 'awaiting_approval')]))
            ->defaultSort('id', 'desc')
            ->heading(__('workspace.billing.invoices'))
            ->columns([
                TextColumn::make('number')->label(__('fields.number'))->weight('semibold')->searchable(),
                TextColumn::make('issued_at')->label(__('fields.issued_at'))->date()->sortable(),
                TextColumn::make('due_at')->label(__('fields.due_at'))->date()->placeholder('—'),
                TextColumn::make('total')->label(__('fields.total'))->alignEnd()->formatStateUsing(fn ($state, Invoice $r) => money((int) $state, $r->currency)),
                TextColumn::make('status')->label(__('fields.status'))->badge()
                    ->state(fn (Invoice $r) => $r->pending_proofs ? 'awaiting' : $r->status->value)
                    ->formatStateUsing(fn ($state) => $state === 'awaiting' ? __('workspace.billing.awaiting_approval') : __('salon.invoice_status.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success', 'open' => 'warning', 'awaiting' => 'info', 'void', 'refunded' => 'gray', default => 'gray'
                    }),
            ])
            ->recordActions([
                Action::make('pdf')->label('PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->url(fn (Invoice $record) => route('tenant.billing.invoices.pdf', $record), shouldOpenInNewTab: true),
                Action::make('payOnline')->label(__('workspace.billing.pay_online'))->icon(Heroicon::OutlinedCreditCard)
                    ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Open && ! $record->pending_proofs && $this->getOnlineDrivers() !== [])
                    ->schema([Select::make('driver')->label(__('fields.gateway'))->required()->native(false)
                        ->options(fn () => collect($this->getOnlineDrivers())->mapWithKeys(fn ($d) => [$d => __('salon.payments.drivers.'.$d)]))->default(fn () => $this->getOnlineDrivers()[0] ?? null)])
                    ->action(function (Invoice $record, array $data) {
                        try {
                            $this->redirect(app(StartPlatformCheckout::class)->handle($record, $data['driver']));
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('uploadProof')->label(__('workspace.billing.upload_proof'))->icon(Heroicon::OutlinedBuildingLibrary)->color('primary')
                    ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Open && ! $record->pending_proofs)
                    ->modalHeading(fn (Invoice $record) => __('workspace.billing.proof_heading', ['number' => $record->number]))
                    ->modalDescription(fn (Invoice $record) => __('workspace.billing.proof_help', ['amount' => money($record->balanceDue(), $record->currency)]))
                    ->schema([
                        FileUpload::make('proof')->label(__('workspace.billing.proof'))->disk('local')->directory(fn () => 'billing-proofs/'.tenant()->id)->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(8192)->required(),
                        TextInput::make('reference')->label(__('workspace.billing.transfer_reference'))->maxLength(120),
                    ])
                    ->action(function (Invoice $record, array $data) {
                        try {
                            app(SubmitBankTransferProof::class)->handle($record, $data['proof'], $data['reference'] ?? null);
                            Notification::make()->success()->title(__('workspace.billing.proof_sent'))->body(__('workspace.billing.proof_sent_body'))->send();
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->emptyStateHeading(__('workspace.billing.no_invoices'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changePlan')->label(__('workspace.billing.change_plan'))->icon(Heroicon::OutlinedArrowsUpDown)
                ->visible(fn () => tenant()->currentSubscription !== null && tenant()->status->canAccessDashboard())
                ->modalDescription(__('workspace.billing.change_help'))
                ->fillForm(function () {
                    $sub = tenant()->currentSubscription;

                    return ['plan_id' => $sub->plan_id, 'branches' => $sub->branches_count, 'modules' => PreviewPlanChange::extraModules($sub)];
                })
                ->schema([
                    Select::make('plan_id')->label(__('fields.plan'))->required()->native(false)->live()
                        ->options(fn () => Plan::where('is_active', true)->orderBy('sort')->get()->pluck('name', 'id')),
                    TextInput::make('branches')->label(__('fields.branches'))->integer()->minValue(fn () => max(1, CreateBranch::count()))->required()->live(debounce: 400)
                        ->helperText(fn () => __('workspace.billing.branches_in_use', ['count' => CreateBranch::count()])),
                    CheckboxList::make('modules')->label(__('workspace.billing.addons'))->columns(2)->live()
                        ->options(fn (Get $get) => Module::where('is_active', true)->orderBy('sort')->get()
                            ->reject(fn ($m) => in_array($m->key, Plan::find($get('plan_id'))?->included_modules ?? [], true))
                            ->mapWithKeys(fn ($m) => [$m->key => $m->name])),
                    Text::make(fn (Get $get) => $this->changePreview($get('plan_id'), $get('branches'), $get('modules') ?? [])),
                ])
                ->action(function (array $data) {
                    try {
                        $invoice = app(RequestPlanChange::class)->handle(tenant(), (int) $data['plan_id'], (int) $data['branches'], $data['modules'] ?? []);
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title($invoice
                        ? __('workspace.billing.change_invoiced', ['number' => $invoice->number])
                        : __('workspace.billing.change_applied'))->send();
                }),
        ];
    }

    public function changePreview(mixed $planId, mixed $branches, array $modules): HtmlString
    {
        if (! $planId) {
            return new HtmlString('');
        }
        try {
            $p = app(PreviewPlanChange::class)->handle(tenant(), (int) $planId, max(1, (int) $branches), $modules);
        } catch (DomainException $e) {
            return new HtmlString('<p class="text-sm text-danger-600">'.e($e->getMessage()).'</p>');
        }
        $currency = $p['quote']->currency;
        $line = $p['prorated'] > 0
            ? __('workspace.billing.preview_charge', ['amount' => money($p['prorated'], $currency), 'days' => $p['subscription']->daysRemaining()])
            : __('workspace.billing.preview_credit', ['amount' => money(abs($p['prorated']), $currency)]);

        return new HtmlString('<div class="rounded-xl bg-gray-50 p-4 text-sm dark:bg-white/5"><p class="font-semibold text-gray-950 dark:text-white">'.e($line).'</p>'
            .'<p class="mt-1 text-gray-500">'.e(__('workspace.billing.preview_renewal', ['amount' => money($p['quote']->total, $currency)])).'</p></div>');
    }
}
