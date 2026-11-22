<?php

namespace App\Filament\Dashboard\Resources\Marketing\WhatsappTemplates;

use App\Domain\Marketing\Actions\SubmitWhatsAppTemplate;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Filament\Dashboard\Resources\Marketing\WhatsappTemplates\Pages\ManageWhatsappTemplates;
use App\Filament\Marketing\GatedResource;
use App\Filament\Marketing\MarketingAccess;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class WhatsappTemplateResource extends Resource
{
    use GatedResource;

    protected static ?string $model = WhatsappTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static ?int $navigationSort = 14;

    public static function getModelLabel(): string
    {
        return __('marketing.templates.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.templates.plural');
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray'
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('marketing.common.name'))->helperText(__('marketing.templates.name_help'))->required()->maxLength(120)->regex('/^[a-z0-9_]+$/'),
            Select::make('language')->label(__('marketing.templates.language'))->options(['en' => 'English', 'ar' => 'العربية', 'en_US' => 'English (US)', 'fr' => 'Français'])->default('en')->required(),
            Select::make('category')->label(__('marketing.templates.category'))->options(array_combine(WhatsappTemplate::CATEGORIES, WhatsappTemplate::CATEGORIES))->default('MARKETING')->required(),
            Select::make('header_type')->label(__('marketing.templates.header_type'))->options(__('marketing.templates.header_types'))->default('none')->live(),
            TextInput::make('header_text')->label(__('marketing.templates.header_text'))->maxLength(60)->visible(fn (Get $get) => $get('header_type') === 'text'),
            TextInput::make('header_media_url')->label(__('marketing.templates.header_media_url'))->url()->maxLength(1000)->visible(fn (Get $get) => in_array($get('header_type'), ['image', 'video', 'document'], true)),
            Textarea::make('body')->label(__('marketing.templates.body'))->helperText(__('marketing.templates.body_help'))->rows(5)->required()->maxLength(1024)->columnSpanFull(),
            TextInput::make('footer')->label(__('marketing.templates.footer'))->maxLength(60)->columnSpanFull(),
            Repeater::make('buttons')->label(__('marketing.templates.buttons'))->columns(3)->maxItems(3)->defaultItems(0)->columnSpanFull()->schema([
                Select::make('type')->label(__('marketing.templates.button_type'))->options(__('marketing.templates.button_types'))->default('url')->required()->live(),
                TextInput::make('text')->label(__('marketing.templates.button_text'))->required()->maxLength(25),
                TextInput::make('url')->label(__('marketing.templates.button_url'))->maxLength(2000)->visible(fn (Get $get) => $get('type') === 'url'),
                TextInput::make('phone')->label(__('marketing.templates.button_phone'))->tel()->visible(fn (Get $get) => $get('type') === 'phone'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->label(__('marketing.common.name'))->weight('semibold')->searchable()->fontFamily('mono')
                ->description(fn (WhatsappTemplate $r) => Str::limit($r->body, 80)),
            TextColumn::make('language')->label(__('marketing.templates.language'))->badge()->color('gray'),
            TextColumn::make('category')->label(__('marketing.templates.category')),
            TextColumn::make('status')->label(__('marketing.common.status'))->badge()->color(fn ($state) => static::statusColor($state))
                ->formatStateUsing(fn ($state) => __('marketing.templates.statuses.'.$state))
                ->description(fn (WhatsappTemplate $r) => $r->rejection_reason),
            TextColumn::make('synced_at')->label(__('marketing.templates.sync'))->since()->placeholder('—'),
        ])->recordActions([
            Action::make('submit')->label(__('marketing.templates.submit'))->icon('heroicon-o-paper-airplane')->color('gray')
                ->visible(fn (WhatsappTemplate $record) => in_array($record->status, ['draft', 'rejected'], true) && MarketingAccess::manage())
                ->requiresConfirmation()
                ->action(function (WhatsappTemplate $record) {
                    try {
                        app(SubmitWhatsAppTemplate::class)->handle($record);
                        Notification::make()->success()->title(__('marketing.templates.submitted'))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            EditAction::make()->slideOver(),
            DeleteAction::make(),
        ])->emptyStateHeading(__('marketing.templates.empty'))->emptyStateDescription(__('marketing.templates.empty_help'))->emptyStateIcon('heroicon-o-chat-bubble-left-ellipsis');
    }

    public static function getPages(): array
    {
        return ['index' => ManageWhatsappTemplates::route('/')];
    }
}
