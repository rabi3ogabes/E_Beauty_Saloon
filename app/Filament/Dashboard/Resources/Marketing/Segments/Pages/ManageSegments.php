<?php

namespace App\Filament\Dashboard\Resources\Marketing\Segments\Pages;

use App\Domain\Marketing\Models\Segment;
use App\Filament\Dashboard\Resources\Marketing\Segments\SegmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSegments extends ManageRecords
{
    protected static string $resource = SegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->slideOver()->after(fn (Segment $record) => $record->refreshCount())];
    }
}
