<?php

namespace App\Domain\POS\Http\Controllers;

use App\Domain\POS\Actions\CloseShift;
use App\Domain\POS\Models\Shift;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Z-report download (stored at close; re-rendered on the fly in another language or if missing). */
class ShiftReportController extends Controller
{
    public function __invoke(Request $request, int $shift, CloseShift $close)
    {
        $user = $request->user();
        abort_unless($user && $user->tenant_id === tenant()?->id && ($user->can('pos.close_shift') || $user->can('pos.cash_drawer') || $user->can('reports.view')), 403);
        $shift = Shift::findOrFail($shift);

        $locale = $request->query('locale');
        $pdf = ! $locale && $shift->z_report_path && Storage::disk('local')->exists($shift->z_report_path)
            ? Storage::disk('local')->get($shift->z_report_path)
            : $close->renderZReport($shift, in_array($locale, ['en', 'ar'], true) ? $locale : null);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="z-report-'.$shift->id.'.pdf"',
        ]);
    }
}
