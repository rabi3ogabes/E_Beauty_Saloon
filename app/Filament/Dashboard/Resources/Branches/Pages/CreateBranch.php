<?php

namespace App\Filament\Dashboard\Resources\Branches\Pages;

use App\Domain\Branches\Actions\CreateBranch as CreateBranchAction;
use App\Domain\Branches\Actions\NormalizeOpeningHours;
use App\Filament\Dashboard\Resources\Branches\BranchResource;
use App\Support\Exceptions\DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    public function mount(): void
    {
        parent::mount();
        if (CreateBranchAction::atLimit()) {
            Notification::make()->warning()->title(__('salon.errors.branch_limit', ['limit' => tenant()->branchLimit()]))->send();
            $this->redirect(BranchResource::getUrl('index'));
        }
    }

    protected function fillForm(): void
    {
        $this->form->fill([
            'hours' => NormalizeOpeningHours::toRows(null), 'timezone' => tenant()->timezone, 'status' => 'active', 'city' => 'Doha',
            'tax_percent' => 0, 'tax_inclusive' => true, 'booking_enabled' => true, 'shop_pickup_enabled' => true,
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['opening_hours'] = app(NormalizeOpeningHours::class)->handle($data['hours'] ?? []);
        unset($data['hours']);
        $data['tax_percent'] ??= 0;
        $data['tax_inclusive'] ??= true;

        try {
            return app(CreateBranchAction::class)->handle($data);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }
}
