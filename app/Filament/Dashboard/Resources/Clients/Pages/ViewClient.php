<?php

namespace App\Filament\Dashboard\Resources\Clients\Pages;

use App\Domain\CRM\Actions\AddClientNote;
use App\Domain\CRM\Actions\AdjustClientPoints;
use App\Domain\CRM\Actions\TopUpClientWallet;
use App\Domain\CRM\Actions\UploadClientPhoto;
use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Support\ClientTimeline;
use App\Filament\Dashboard\Resources\Clients\ClientResource;
use App\Filament\Support\Money;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/** Client profile: key figures, contact & consents, private allergies, and the activity timeline. */
class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return collect([$this->record->phone, $this->record->email])->filter()->join(' · ');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Grid::make(['default' => 2, 'md' => 3, 'xl' => 6])->columnSpanFull()->schema(array_values(array_filter([
                ClientResource::canSeeRevenue() ? static::stat('lifetime_value', __('workspace.clients.ltv'), fn ($s) => money((int) $s)) : null,
                static::stat('visits_count', __('workspace.clients.visits')),
                static::stat('last_visit_at', __('workspace.clients.last_visit'), fn ($s) => $s ? Carbon::parse($s)->diffForHumans() : __('workspace.clients.never')),
                static::stat('wallet_balance', __('workspace.clients.wallet'), fn ($s) => money((int) $s)),
                static::stat('loyalty_points', __('workspace.clients.points'), fn ($s, $record) => number_format((int) $s).($record->loyalty_tier ? ' · '.ucfirst($record->loyalty_tier) : '')),
                static::stat('no_shows_count', __('workspace.clients.no_shows')),
            ]))),
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Section::make(__('workspace.clients.profile'))->schema([
                    TextEntry::make('phone')->label(__('fields.phone'))->copyable()->icon(Heroicon::OutlinedPhone),
                    TextEntry::make('email')->label(__('fields.email'))->placeholder('—')->copyable(),
                    TextEntry::make('birthday')->label(__('workspace.clients.birthday'))->date('d M')->placeholder('—'),
                    TextEntry::make('tags.name')->label(__('workspace.clients.tags'))->badge()->placeholder('—'),
                    TextEntry::make('preferredBranch.name')->label(__('workspace.clients.preferred_branch'))->placeholder('—'),
                    TextEntry::make('preferredStaff.name')->label(__('workspace.clients.preferred_staff'))->placeholder('—'),
                    TextEntry::make('source')->label(__('fields.source'))->formatStateUsing(fn ($state) => __('workspace.clients.sources.'.$state))->placeholder('—'),
                ]),
                Section::make(__('workspace.clients.allergies'))->icon(Heroicon::OutlinedExclamationTriangle)->iconColor('danger')
                    ->visible(fn () => ClientResource::canSeePrivate())
                    ->schema([
                        TextEntry::make('allergies_private')->hiddenLabel()->state(fn (Client $record) => $record->allergies)->placeholder(__('workspace.clients.no_allergies'))->color('danger'),
                    ]),
                Section::make(__('workspace.clients.consents'))->schema(
                    collect(Client::CHANNELS)->map(fn ($c) => TextEntry::make('consent_'.$c)->label(__('workspace.clients.channels.'.$c))
                        ->state(fn (Client $record) => ($consent = $record->consents->firstWhere('channel', $c)) ? ($consent->granted ? __('workspace.clients.opted_in') : __('workspace.clients.opted_out')) : __('workspace.clients.no_answer'))
                        ->badge()->color(fn ($state) => $state === __('workspace.clients.opted_in') ? 'success' : 'gray')
                        ->helperText(fn (Client $record) => $record->consents->firstWhere('channel', $c)?->updated_at?->translatedFormat('d M Y, H:i')))->all()
                ),
            ]),
            Section::make(__('workspace.clients.timeline'))->columnSpan(['default' => 3, 'lg' => 2])->schema([
                View::make('filament.dashboard.clients.timeline')->viewData(fn (Client $record) => ['events' => ClientTimeline::for($record)]),
            ]),
        ]);
    }

    protected static function stat(string $name, string $label, ?\Closure $format = null): TextEntry
    {
        return TextEntry::make($name)->label($label)->size('lg')->weight('semibold')
            ->formatStateUsing($format)->placeholder('0')
            ->extraAttributes(['class' => 'rounded-2xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5']);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }

    protected function getHeaderActions(): array
    {
        $manage = fn () => ClientResource::canManage();
        $guard = function (\Closure $run) {
            try {
                $run();
            } catch (DomainException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };

        return [
            Action::make('addNote')->label(__('workspace.clients.add_note'))->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->visible($manage)
                ->schema([
                    Textarea::make('body')->label(__('workspace.clients.note'))->required()->rows(4),
                    Toggle::make('is_private')->label(__('workspace.clients.private_note'))->helperText(__('workspace.clients.private_note_help'))->default(true)
                        ->visible(fn () => ClientResource::canSeePrivate()),
                ])
                ->action(fn (array $data) => $guard(function () use ($data) {
                    app(AddClientNote::class)->handle($this->record, $data['body'], (bool) ($data['is_private'] ?? false));
                    Notification::make()->success()->title(__('workspace.clients.note_added'))->send();
                })),
            ActionGroup::make([
                Action::make('topUp')->label(__('workspace.clients.top_up'))->icon(Heroicon::OutlinedWallet)->visible($manage)
                    ->modalDescription(fn () => __('workspace.clients.wallet_balance', ['amount' => money((int) $this->record->wallet_balance)]))
                    ->schema([
                        Money::input('amount')->label(__('fields.amount'))->required()->minValue(0.01),
                        Select::make('type')->label(__('fields.type'))->native(false)->default('topup')
                            ->options(['topup' => __('workspace.clients.wallet_types.topup'), 'store_credit' => __('workspace.clients.wallet_types.store_credit')]),
                        TextInput::make('note')->label(__('fields.notes'))->maxLength(200)->placeholder(__('workspace.clients.top_up_placeholder')),
                    ])
                    ->action(fn (array $data) => $guard(function () use ($data) {
                        app(TopUpClientWallet::class)->handle($this->record, (int) $data['amount'], $data['type'] ?? 'topup', $data['note'] ?? null);
                        $this->record->refresh();
                        Notification::make()->success()->title(__('workspace.clients.topped_up', ['amount' => money((int) $this->record->wallet_balance)]))->send();
                    })),
                Action::make('adjustPoints')->label(__('workspace.clients.adjust_points'))->icon(Heroicon::OutlinedGift)
                    ->visible(fn () => ClientResource::canManage() && auth()->user()?->can('settings.manage'))
                    ->modalDescription(fn () => __('workspace.clients.points_balance', ['points' => $this->record->loyalty_points]))
                    ->schema([
                        TextInput::make('points')->label(__('workspace.clients.points_change'))->helperText(__('workspace.clients.points_change_help'))->integer()->required()->notIn([0]),
                        TextInput::make('reason')->label(__('fields.reason'))->required()->maxLength(200),
                    ])
                    ->action(fn (array $data) => $guard(function () use ($data) {
                        app(AdjustClientPoints::class)->handle($this->record, (int) $data['points'], $data['reason']);
                        $this->record->refresh();
                        Notification::make()->success()->title(__('workspace.clients.points_adjusted', ['points' => $this->record->loyalty_points]))->send();
                    })),
                Action::make('uploadPhoto')->label(__('workspace.clients.upload_photo'))->icon(Heroicon::OutlinedCamera)->visible($manage)
                    ->schema([
                        FileUpload::make('path')->label(__('workspace.clients.photo'))->image()->disk('local')->directory(fn () => 'client-photos/'.$this->record->tenant_id)->visibility('private')->maxSize(8192)->required(),
                        Select::make('kind')->label(__('fields.type'))->native(false)->default('before')
                            ->options(collect(['before', 'after', 'reference'])->mapWithKeys(fn ($k) => [$k => __('workspace.clients.photo_kinds.'.$k)])),
                        TextInput::make('caption')->label(__('workspace.clients.caption'))->maxLength(160),
                    ])
                    ->action(fn (array $data) => $guard(function () use ($data) {
                        app(UploadClientPhoto::class)->handle($this->record, $data['path'], $data['kind'] ?? 'before', $data['caption'] ?? null);
                        Notification::make()->success()->title(__('workspace.clients.photo_added'))->send();
                    })),
            ])->label(__('workspace.common.more'))->icon(Heroicon::OutlinedEllipsisHorizontal)->button()->color('gray'),
            EditAction::make(),
        ];
    }
}
