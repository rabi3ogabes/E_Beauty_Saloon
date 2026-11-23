<?php

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('admin.invoice.plural');
    }

    public function table(Table $table): Table
    {
        return InvoiceResource::table($table)->defaultSort('id', 'desc');
    }
}
