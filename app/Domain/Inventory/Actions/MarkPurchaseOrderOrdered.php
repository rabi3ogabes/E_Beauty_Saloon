<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\PurchaseOrder;
use App\Support\Exceptions\DomainException;

class MarkPurchaseOrderOrdered
{
    public function handle(PurchaseOrder $po): PurchaseOrder
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));

        if ($po->status !== 'draft') {
            throw new DomainException(__('workspace.inventory.po_not_draft'));
        }
        $po->update(['status' => 'ordered']);
        activity()->performedOn($po)->causedBy(auth()->user())->log('purchase_order.ordered');

        return $po;
    }
}
