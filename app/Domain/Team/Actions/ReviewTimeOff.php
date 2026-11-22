<?php

namespace App\Domain\Team\Actions;

use App\Domain\Team\Models\TimeOff;
use App\Support\Exceptions\DomainException;

/** Approve or reject a time-off request. */
class ReviewTimeOff
{
    public function handle(TimeOff $request, bool $approve): TimeOff
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));

        if ($request->status !== 'pending') {
            throw new DomainException(__('workspace.time_off.already_reviewed'));
        }

        $request->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => auth()->id()]);
        activity()->performedOn($request)->causedBy(auth()->user())->log($approve ? 'time_off.approved' : 'time_off.rejected');

        return $request;
    }
}
