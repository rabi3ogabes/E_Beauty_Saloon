<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\Request;

/** Invoice PDF for platform staff (central) and the owning salon (tenant domain). */
class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, PdfRenderer $pdf)
    {
        $user = $request->user();
        abort_unless($user && (($user->is_platform && $user->can('platform.billing.view')) || ($user->tenant_id === $invoice->tenant_id && $user->can('settings.billing'))), 403);

        $locale = in_array($request->query('locale'), ['en', 'ar'], true) ? $request->query('locale') : app()->getLocale();
        $invoice->load('items', 'tenant');
        $qr = PdfRenderer::qr($invoice->number.'|'.$invoice->total.'|'.$invoice->currency.'|'.$invoice->issued_at->toDateString());

        return response($pdf->render('pdf.invoice', ['invoice' => $invoice, 'qr' => $qr, 'title' => $invoice->number], $locale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
        ]);
    }
}
