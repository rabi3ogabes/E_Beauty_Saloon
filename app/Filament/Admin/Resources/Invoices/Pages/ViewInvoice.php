<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InvoiceResource::markPaidAction(),
            Action::make('pdfEn')->label('PDF · EN')->icon('heroicon-o-arrow-down-tray')->color('gray')->url(fn () => route('admin.invoices.pdf', [$this->record, 'locale' => 'en']), true),
            Action::make('pdfAr')->label('PDF · ع')->icon('heroicon-o-arrow-down-tray')->color('gray')->url(fn () => route('admin.invoices.pdf', [$this->record, 'locale' => 'ar']), true),
        ];
    }
}
