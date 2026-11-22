<?php

namespace App\Filament\Pos\Pages;

use App\Domain\POS\Actions\BuildShiftSummary;
use App\Domain\POS\Actions\CloseShift;
use App\Domain\POS\Actions\RecordCashMovement;
use App\Domain\POS\Actions\VerifyPosPin;
use App\Domain\POS\Models\Register;
use App\Domain\POS\Models\Shift;
use App\Filament\Support\Money;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/** Drawer: X-report of the open shift, paid in / paid out, close with count → Z-report. */
class CashDrawer extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'cash-drawer';

    protected string $view = 'filament.pos.cash-drawer';

    public ?int $registerId = null;

    public static function canAccess(): bool
    {
        return (bool) tenant()?->hasModule('pos') && (auth()->user()?->can('pos.cash_drawer') ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.nav.cash_drawer');
    }

    public function getTitle(): string
    {
        return __('pos.nav.cash_drawer');
    }

    public function mount(): void
    {
        $remembered = (int) session('pos.register_id');
        $this->registerId = $this->registers->firstWhere('id', $remembered)?->id ?? $this->registers->first()?->id;
    }

    #[Computed]
    public function registers(): Collection
    {
        return Register::query()->where('is_active', true)->with('branch')->orderBy('name')->get();
    }

    #[Computed]
    public function shift(): ?Shift
    {
        return $this->registerId ? Shift::where('pos_register_id', $this->registerId)->where('status', 'open')->with(['user', 'cashMovements.user'])->first() : null;
    }

    #[Computed]
    public function summary(): ?array
    {
        return $this->shift ? app(BuildShiftSummary::class)->handle($this->shift) : null;
    }

    #[Computed]
    public function recentShifts(): Collection
    {
        return Shift::where('pos_register_id', $this->registerId)->where('status', 'closed')->with(['user', 'closer'])->latest('closed_at')->limit(8)->get();
    }

    public function selectRegister(int $id): void
    {
        if ($this->registers->firstWhere('id', $id)) {
            $this->registerId = $id;
            session(['pos.register_id' => $id]);
            unset($this->shift, $this->summary, $this->recentShifts);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->movementAction('in'),
            $this->movementAction('out'),
            Action::make('closeShift')->label(__('pos.shift.close'))->icon(Heroicon::OutlinedLockClosed)->color('primary')
                ->visible(fn () => $this->shift !== null)
                ->modalHeading(__('pos.shift.close'))
                ->modalDescription(fn () => __('pos.shift.expected').': '.money((int) ($this->summary['expected_cash'] ?? 0)))
                ->schema([
                    Money::input('counted')->label(__('pos.shift.counted'))->required(),
                    Textarea::make('notes')->label(__('pos.shift.notes'))->rows(2),
                    TextInput::make('manager_pin')->label(__('pos.refund.manager_pin'))->password()
                        ->visible(fn () => ! auth()->user()?->can('pos.close_shift'))->required(fn () => ! auth()->user()?->can('pos.close_shift')),
                ])
                ->action(function (array $data, Action $action) {
                    try {
                        $user = auth()->user();
                        $manager = $user->can('pos.close_shift') ? null : app(VerifyPosPin::class)->handle((string) ($data['manager_pin'] ?? ''), 'pos.close_shift');
                        $shift = app(CloseShift::class)->handle($this->shift, $user, (int) $data['counted'], $data['notes'] ?? null, $manager);
                        unset($this->shift, $this->summary, $this->recentShifts);
                        Notification::make()->success()->title(__('pos.shift.closed'))
                            ->body(__('pos.shift.variance').': '.money((int) $shift->variance))
                            ->actions([Action::make('z')->label(__('pos.shift.z_report'))->url(route('pos.shift.z-report', $shift->id), shouldOpenInNewTab: true)])
                            ->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }
                }),
        ];
    }

    protected function movementAction(string $type): Action
    {
        return Action::make('cash'.ucfirst($type))->label(__('pos.shift.movement_'.$type))
            ->icon($type === 'in' ? Heroicon::OutlinedArrowDownTray : Heroicon::OutlinedArrowUpTray)->color('gray')
            ->visible(fn () => $this->shift !== null)
            ->schema([
                Money::input('amount')->label(__('pos.shift.amount'))->required(),
                TextInput::make('reason')->label(__('pos.shift.reason'))->required()->maxLength(190),
            ])
            ->action(function (array $data, Action $action) use ($type) {
                try {
                    app(RecordCashMovement::class)->handle($this->shift, auth()->user(), $type, (int) $data['amount'], (string) $data['reason']);
                    unset($this->shift, $this->summary);
                    Notification::make()->success()->title(__('pos.shift.movement_'.$type).' · '.money((int) $data['amount']))->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
            });
    }
}
