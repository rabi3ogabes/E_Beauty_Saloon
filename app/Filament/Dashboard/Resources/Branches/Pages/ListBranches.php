<?php

namespace App\Filament\Dashboard\Resources\Branches\Pages;

use App\Domain\Branches\Actions\CreateBranch as CreateBranchAction;
use App\Domain\Branches\Actions\PurchaseBranchSlot;
use App\Domain\Workspace\Actions\PreviewPlanChange;
use App\Filament\Dashboard\Resources\Branches\BranchResource;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ListBranches extends ListRecords
{
    protected static string $resource = BranchResource::class;

    public function getSubheading(): ?string
    {
        return __('workspace.branches.usage', ['used' => CreateBranchAction::count(), 'limit' => tenant()->branchLimit()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('workspace.branches.add'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => BranchResource::canCreate() && ! CreateBranchAction::atLimit()),
            static::upgradeAction(),
        ];
    }

    /** At the plan limit: show the prorated price of one more branch and issue the proration invoice. */
    public static function upgradeAction(): Action
    {
        return Action::make('addBranchUpgrade')
            ->label(__('workspace.branches.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => BranchResource::canCreate() && CreateBranchAction::atLimit())
            ->modalIcon(Heroicon::OutlinedSparkles)
            ->modalHeading(__('workspace.branches.limit_heading'))
            ->modalDescription(fn () => __('salon.errors.branch_limit', ['limit' => tenant()->branchLimit()]))
            ->schema([
                Text::make(fn () => static::upgradeQuote()),
            ])
            ->modalSubmitActionLabel(__('workspace.branches.upgrade_cta'))
            ->action(function () {
                try {
                    $invoice = app(PurchaseBranchSlot::class)->handle(tenant());
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()
                    ->title($invoice ? __('workspace.branches.upgrade_invoiced', ['number' => $invoice->number]) : __('workspace.branches.upgrade_applied'))
                    ->body($invoice ? __('workspace.branches.upgrade_invoiced_body') : null)
                    ->actions($invoice ? [Action::make('pay')->label(__('salon.billing.pay_now'))->url(url('/dashboard/billing'))] : [])
                    ->send();
            });
    }

    public static function upgradeQuote(): HtmlString
    {
        try {
            $p = app(PreviewPlanChange::class)->handle(tenant(), branches: tenant()->branchLimit() + 1);
        } catch (DomainException $e) {
            return new HtmlString('<p class="text-sm text-danger-600">'.e($e->getMessage()).'</p>');
        }
        $currency = $p['quote']->currency;

        return new HtmlString(view('filament.dashboard.partials.upgrade-quote', [
            'prorated' => money(max(0, $p['prorated']), $currency),
            'days' => $p['subscription']->daysRemaining(),
            'renewal' => money($p['quote']->total, $currency),
            'cycle' => $p['quote']->cycleMonths,
            'branches' => $p['branches'],
        ])->render());
    }
}
