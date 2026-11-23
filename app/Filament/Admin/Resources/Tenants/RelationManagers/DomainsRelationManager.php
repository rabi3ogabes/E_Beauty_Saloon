<?php

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Domain\Tenancy\Actions\AddCustomDomain;
use App\Domain\Tenancy\Jobs\VerifyCustomDomain;
use App\Domain\Tenancy\Models\Domain;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DomainsRelationManager extends RelationManager
{
    protected static string $relationship = 'domains';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.domain.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain')->label(__('fields.domain'))->weight('semibold')->copyable()
                    ->url(fn (Domain $r) => $r->url(), shouldOpenInNewTab: true),
                TextColumn::make('type')->label(__('fields.type'))->badge()->color('gray'),
                IconColumn::make('is_primary')->label(__('admin.domain.primary'))->boolean(),
                TextColumn::make('verified_at')->label(__('admin.domain.verified'))->since()->placeholder(__('admin.domain.pending')),
                TextColumn::make('ssl_status')->label('SSL')->badge()->color(fn ($state) => match ($state) {
                    'active' => 'success', 'failed' => 'danger', default => 'warning'
                }),
                TextColumn::make('last_error')->label(__('admin.domain.last_check'))->color('gray')->wrap()->placeholder('—'),
            ])
            ->headerActions([
                Action::make('addDomain')->label(__('admin.domain.add'))->icon('heroicon-o-plus')
                    ->schema([TextInput::make('domain')->label(__('fields.domain'))->placeholder('book.mysalon.com')->required()])
                    ->action(function (array $data) {
                        try {
                            app(AddCustomDomain::class)->handle($this->getOwnerRecord(), $data['domain']);
                            Notification::make()->success()->title(__('admin.domain.added'))->send();
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('dns')->label(__('admin.domain.dns'))->icon('heroicon-o-information-circle')->color('gray')
                    ->visible(fn (Domain $r) => $r->type === 'custom')
                    ->modalContent(fn (Domain $r) => view('filament.admin.dns-instructions', ['records' => AddCustomDomain::instructions($r)]))
                    ->modalSubmitAction(false),
                Action::make('verify')->label(__('admin.domain.verify_now'))->icon('heroicon-o-arrow-path')
                    ->visible(fn (Domain $r) => $r->type === 'custom' && ($r->verified_at === null || $r->ssl_status !== 'active'))
                    ->action(function (Domain $r) {
                        VerifyCustomDomain::dispatchSync($r->id);
                        $r->refresh();
                        Notification::make()->{$r->verified_at ? 'success' : 'warning'}()
                            ->title($r->verified_at ? __('admin.domain.verified_ok') : ($r->last_error ?? __('admin.domain.not_yet')))->send();
                    }),
                Action::make('primary')->label(__('admin.domain.make_primary'))->icon('heroicon-o-star')->color('gray')
                    ->visible(fn (Domain $r) => ! $r->is_primary && $r->isVerified())
                    ->action(function (Domain $r) {
                        $r->tenant->domains()->update(['is_primary' => false]);
                        $r->update(['is_primary' => true]);
                    }),
                DeleteAction::make()->visible(fn (Domain $r) => $r->type === 'custom'),
            ]);
    }
}
