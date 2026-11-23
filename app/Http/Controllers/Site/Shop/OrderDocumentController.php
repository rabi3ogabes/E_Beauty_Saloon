<?php

namespace App\Http\Controllers\Site\Shop;

use App\Domain\Shop\Models\Order;
use App\Http\Controllers\Controller;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\Request;

/** Packing slip and invoice PDFs (Arabic or English) for staff. */
class OrderDocumentController extends Controller
{
    public function packingSlip(Request $request, Order $order, PdfRenderer $pdf)
    {
        return $this->render($request, $order, $pdf, 'packing-slip');
    }

    public function invoice(Request $request, Order $order, PdfRenderer $pdf)
    {
        return $this->render($request, $order, $pdf, 'invoice');
    }

    protected function render(Request $request, Order $order, PdfRenderer $pdf, string $doc)
    {
        abort_unless($request->user('web')?->tenant_id === tenant()?->id && $request->user('web')->can('shop.view'), 403);
        $locale = in_array($request->query('lang'), ['en', 'ar'], true) ? $request->query('lang') : app()->getLocale();
        $order->load(['items', 'branch' => fn ($q) => $q->withoutGlobalScopes(), 'zone', 'driver', 'payments']);

        $title = __('shop.pdf.'.str_replace('-', '_', $doc), [], $locale).' '.$order->number;
        $content = $pdf->render('pdf.shop.'.$doc, ['order' => $order, 'tenant' => tenant(), 'title' => $title], $locale);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$order->number.'-'.$doc.'-'.$locale.'.pdf"',
        ]);
    }
}
