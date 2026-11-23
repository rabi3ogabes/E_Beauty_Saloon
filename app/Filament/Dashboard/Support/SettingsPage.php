<?php

namespace App\Filament\Dashboard\Support;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;

/**
 * Base for workspace settings pages: one form bound to $data with a sticky Save button.
 * Subclasses implement form(), fillData() and save().
 *
 * @property-read Schema $form
 */
abstract class SettingsPage extends Page
{
    public ?array $data = [];

    abstract protected function fillData(): array;

    abstract public function save(): void;

    public function mount(): void
    {
        $this->form->fill($this->fillData());
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function isReadOnly(): bool
    {
        return tenant()?->isReadOnly() ?? true;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('salon.ui.save'))->submit('save')->keyBindings(['mod+s'])->visible(fn () => ! $this->isReadOnly()),
                    ])->sticky()->key('form-actions'),
                ]),
        ]);
    }
}
