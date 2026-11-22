<?php

namespace App\Filament\Dashboard\Pages;

use App\Domain\Tenancy\Actions\AddCustomDomain;
use App\Domain\Tenancy\Jobs\VerifyCustomDomain;
use App\Domain\Tenancy\Models\Domain;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Custom domains: add, see the DNS records to create (with copy buttons), status and "check now". */
class Domains extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'domains';

    protected string $view = 'filament.dashboard.pages.domains';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('workspace.domains.title');
    }

    public function getTitle(): string
    {
        return __('workspace.domains.title');
    }

    public function getSubheading(): ?string
    {
        return __('workspace.domains.subheading');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('settings.manage');
    }

    public function getDomains()
    {
        return tenant()->domains()->orderByDesc('is_primary')->orderBy('id')->get();
    }

    public function instructions(Domain $domain): array
    {
        return AddCustomDomain::instructions($domain);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addDomain')->label(__('workspace.domains.add'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => ! tenant()->isReadOnly())
                ->modalDescription(__('workspace.domains.add_help'))
                ->schema([
                    TextInput::make('domain')->label(__('fields.domain'))->required()->placeholder('book.mysalon.com')->maxLength(190)->prefix('https://'),
                ])
                ->action(function (array $data) {
                    try {
                        $domain = app(AddCustomDomain::class)->handle(tenant(), $data['domain']);
                        activity()->performedOn(tenant())->causedBy(auth()->user())->withProperties(['domain' => $domain->domain])->log('domain.added');
                        Notification::make()->success()->title(__('workspace.domains.added', ['domain' => $domain->domain]))->body(__('workspace.domains.added_body'))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }

    public function checkNow(int $domainId): void
    {
        $domain = tenant()->domains()->whereKey($domainId)->where('type', 'custom')->firstOrFail();
        VerifyCustomDomain::dispatchSync($domain->id);
        $domain->refresh();

        $domain->isVerified()
            ? Notification::make()->success()->title(__('workspace.domains.verified', ['domain' => $domain->domain]))->send()
            : Notification::make()->warning()->title(__('workspace.domains.not_yet'))->body($domain->last_error)->send();
    }

    public function makePrimary(int $domainId): void
    {
        abort_if(tenant()->isReadOnly(), 423);
        $domain = tenant()->domains()->whereKey($domainId)->whereNotNull('verified_at')->firstOrFail();
        tenant()->domains()->update(['is_primary' => false]);
        $domain->update(['is_primary' => true]);
        activity()->performedOn(tenant())->causedBy(auth()->user())->withProperties(['domain' => $domain->domain])->log('domain.primary');
        Notification::make()->success()->title(__('workspace.domains.primary_set', ['domain' => $domain->domain]))->send();
    }

    public function removeDomain(int $domainId): void
    {
        abort_if(tenant()->isReadOnly(), 423);
        $domain = tenant()->domains()->whereKey($domainId)->where('type', 'custom')->where('is_primary', false)->firstOrFail();
        $domain->delete();
        activity()->performedOn(tenant())->causedBy(auth()->user())->withProperties(['domain' => $domain->domain])->log('domain.removed');
        Notification::make()->success()->title(__('workspace.domains.removed'))->send();
    }
}
