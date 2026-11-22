<?php

namespace App\Domain\POS\Actions;

use App\Domain\POS\Models\Shift;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Counts the drawer, records the variance and stores the Z-report PDF. */
class CloseShift
{
    public function __construct(protected BuildShiftSummary $summary, protected PdfRenderer $pdf) {}

    public function handle(Shift $shift, User $user, int $countedCash, ?string $notes = null): Shift
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        if (! $user->can('pos.close_shift') && ! $user->can('pos.cash_drawer')) {
            throw new DomainException(__('pos.errors.forbidden'));
        }

        $shift = DB::transaction(function () use ($shift, $user, $countedCash, $notes) {
            $shift = Shift::withoutGlobalScopes()->lockForUpdate()->findOrFail($shift->id);
            if (! $shift->isOpen()) {
                throw new DomainException(__('pos.errors.shift_closed'));
            }
            $summary = $this->summary->handle($shift);
            $shift->forceFill([
                'status' => 'closed',
                'closed_by' => $user->id,
                'closed_at' => now(),
                'expected_cash' => $summary['expected_cash'],
                'counted_cash' => max(0, $countedCash),
                'variance' => max(0, $countedCash) - $summary['expected_cash'],
                'summary' => $summary,
                'notes' => $notes,
            ])->save();
            activity()->performedOn($shift)->causedBy($user)->withProperties(['expected' => $summary['expected_cash'], 'counted' => $countedCash, 'variance' => $shift->variance])->log('pos.shift.closed');

            return $shift;
        });

        $path = 'pos/z-reports/'.$shift->tenant_id.'/z-'.$shift->id.'.pdf';
        Storage::disk('local')->put($path, $this->renderZReport($shift));
        $shift->forceFill(['z_report_path' => $path])->save();

        return $shift;
    }

    public function renderZReport(Shift $shift, ?string $locale = null): string
    {
        $shift->loadMissing(['register', 'user', 'closer', 'cashMovements', 'branch']);

        return $this->pdf->render('pdf.pos.z-report', [
            'shift' => $shift,
            'summary' => $shift->summary ?? $this->summary->handle($shift),
            'tenant' => tenant(),
            'title' => __('pos.shift.z_report').' #'.$shift->id,
        ], $locale, 'A4');
    }
}
