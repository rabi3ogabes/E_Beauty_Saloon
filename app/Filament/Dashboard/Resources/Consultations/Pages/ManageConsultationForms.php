<?php

namespace App\Filament\Dashboard\Resources\Consultations\Pages;

use App\Domain\CRM\Models\Client;
use App\Domain\Insights\Actions\SendConsultationForm;
use App\Filament\Dashboard\Resources\Consultations\ConsultationFormResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageConsultationForms extends ManageRecords
{
    protected static string $resource = ConsultationFormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')->label(__('insights.consultation.send'))->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn () => (auth()->user()?->can('clients.manage') ?? false) && ! tenant()->isReadOnly())
                ->schema([
                    Select::make('client_id')->label(__('insights.reports.cols.clients'))->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Client::query()->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                            ->limit(20)->get()->mapWithKeys(fn ($c) => [$c->id => $c->name.' · '.$c->phone]))
                        ->getOptionLabelUsing(fn ($value) => Client::find($value)?->name),
                ])
                ->action(function (array $data) {
                    app(SendConsultationForm::class)->handle(Client::findOrFail($data['client_id']));
                    Notification::make()->success()->title(__('insights.consultation.sent_ok'))->send();
                }),
        ];
    }
}
