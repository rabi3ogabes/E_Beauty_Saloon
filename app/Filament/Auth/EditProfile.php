<?php

namespace App\Filament\Auth;

use App\Domain\Team\Actions\SetPosPin;
use App\Support\Exceptions\DomainException;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** Salon profile page (dashboard + POS): the standard name / email / password fields plus the member's own POS PIN. */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getPosPinSection(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    public static function usesPosPin(): bool
    {
        $user = Filament::auth()->user();

        return (bool) tenant()?->hasModule('pos') && $user && ($user->isOwner() || $user->can('pos.access'));
    }

    protected function getPosPinSection(): Component
    {
        return Section::make(__('workspace.pin.title'))
            ->description(fn () => __($this->getUser()->pos_pin ? 'workspace.pin.has_pin' : 'workspace.pin.no_pin'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->visible(fn () => static::usesPosPin())
            ->inlineLabel(false)
            ->compact()
            ->schema([
                TextInput::make('posPin')->label(__('workspace.pin.new'))
                    ->helperText(__('workspace.pin.help', ['min' => SetPosPin::MIN, 'max' => SetPosPin::MAX]))
                    ->password()->revealable()->autocomplete('off')
                    ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => SetPosPin::MAX])
                    ->regex('/^\d{'.SetPosPin::MIN.','.SetPosPin::MAX.'}$/')
                    ->validationMessages(['regex' => __('workspace.pin.format', ['min' => SetPosPin::MIN, 'max' => SetPosPin::MAX])])
                    ->same('posPinConfirmation')
                    ->disabled(fn (Get $get) => (bool) $get('removePosPin'))
                    ->live(debounce: 500)
                    ->dehydrated(false),
                TextInput::make('posPinConfirmation')->label(__('workspace.pin.confirm'))
                    ->password()->revealable()->autocomplete('off')
                    ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => SetPosPin::MAX])
                    ->required()
                    ->visible(fn (Get $get) => filled($get('posPin')))
                    ->dehydrated(false),
                Toggle::make('removePosPin')->label(__('workspace.pin.remove'))
                    ->visible(fn () => filled($this->getUser()->pos_pin))
                    ->live()
                    ->dehydrated(false),
            ]);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        // Changing the PIN (or removing it) also asks for the current password.
        return parent::getCurrentPasswordFormComponent()
            ->visible(fn (Get $get): bool => filled($get('password'))
                || ($get('email') !== $this->getUser()->getAttributeValue('email'))
                || filled($get('posPin')) || (bool) $get('removePosPin'));
    }

    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $record = parent::handleRecordUpdate($record, $data);

        if (! static::usesPosPin()) {
            return $record;
        }

        $remove = (bool) ($this->data['removePosPin'] ?? false);
        $pin = (string) ($this->data['posPin'] ?? '');
        if (! $remove && $pin === '') {
            return $record;
        }

        try {
            app(SetPosPin::class)->handle($record, $remove ? null : $pin);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.posPin' => $e->getMessage()]);
        }

        $this->data['posPin'] = $this->data['posPinConfirmation'] = null;
        $this->data['removePosPin'] = false;

        return $record;
    }
}
