<?php

namespace App\Filament\Dashboard\Pages\AIStudio;

use App\Domain\AIStudio\Actions\PurchaseCreditPack;
use App\Domain\AIStudio\Models\AICreditPack;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\AIStudio\Models\ClientLook;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Credit wallet: balance, buy packs (platform invoice), history and usage stats. */
class AIStudioWallet extends Page
{
    protected static ?string $slug = 'ai-studio/credits';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.dashboard.ai-studio.wallet';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.ai_studio');
    }

    public static function getNavigationLabel(): string
    {
        return __('ai_studio.dashboard.wallet');
    }

    public function getTitle(): string
    {
        return __('ai_studio.dashboard.wallet');
    }

    public static function canAccess(): bool
    {
        return (bool) (tenant()?->hasModule('ai_studio') && auth()->user()?->can('ai_studio.view'));
    }

    public function buy(int $packId): void
    {
        abort_unless(auth()->user()?->can('ai_studio.manage') && auth()->user()?->can('settings.billing'), 403);
        try {
            $invoice = app(PurchaseCreditPack::class)->handle(tenant(), AICreditPack::findOrFail($packId));
            Notification::make()->success()->title(__('ai_studio.dashboard.invoice_issued', ['number' => $invoice->number]))->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    protected function getViewData(): array
    {
        $tenant = tenant();
        $wallet = AIWallet::for($tenant);
        $monthStart = now()->startOfMonth();
        $jobs = AIJob::where('created_at', '>=', $monthStart);
        $total = (clone $jobs)->count();

        return [
            'wallet' => $wallet,
            'packs' => AICreditPack::active()->get(),
            'transactions' => $wallet->transactions()->limit(25)->get(),
            'stats' => [
                'jobs' => (clone $jobs)->where('status', 'succeeded')->count(),
                'credits' => (int) (clone $jobs)->sum('charged_credits'),
                'success' => $total ? round((clone $jobs)->where('status', 'succeeded')->count() / $total * 100) : null,
            ],
            'topStyles' => ClientLook::query()->where('client_looks.created_at', '>=', $monthStart)->whereNotNull('style_id')
                ->selectRaw('style_id, COUNT(*) as uses')->groupBy('style_id')->orderByDesc('uses')->limit(5)->with('style')->get(),
            'canBuy' => auth()->user()?->can('ai_studio.manage') && auth()->user()?->can('settings.billing'),
        ];
    }
}
