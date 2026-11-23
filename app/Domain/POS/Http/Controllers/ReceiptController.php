<?php

namespace App\Domain\POS\Http\Controllers;

use App\Domain\Booking\Support\BookingLinks;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Support\ThermalPdf;
use App\Http\Controllers\Controller;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\Request;

/**
 * Receipts: 80mm thermal HTML (window.print with @page 80mm), PDF (mPDF, Arabic shaping) and the
 * signed public page shared by WhatsApp / email.
 */
class ReceiptController extends Controller
{
    public function print(Request $request, int $sale)
    {
        $this->authorizePos($request);

        return view('pos.receipt', $this->data(Sale::findOrFail($sale), $this->locale($request)) + ['autoPrint' => ! $request->boolean('preview')]);
    }

    public function pdf(Request $request, int $sale, ThermalPdf $pdf)
    {
        $this->authorizePos($request);
        $sale = Sale::findOrFail($sale);
        $locale = $this->locale($request);
        $data = $this->data($sale, $locale);
        $height = 150 + 9 * ($sale->items->count() + $sale->salePayments->count());

        return response($pdf->render('pdf.pos.receipt', $data + ['title' => $sale->number], $locale, 80, $height), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$sale->number.'.pdf"',
        ]);
    }

    public function public(Request $request, int $sale)
    {
        $sale = Sale::withoutGlobalScopes()->where('tenant_id', tenant()?->id)->findOrFail($sale);
        $locale = in_array($request->query('locale'), ['en', 'ar'], true) ? $request->query('locale') : ($sale->client?->locale ?? app()->getLocale());
        app()->setLocale($locale);

        return view('pos.receipt', $this->data($sale, $locale) + ['autoPrint' => false, 'public' => true]);
    }

    /** @return array<string, mixed> */
    public static function data(Sale $sale, string $locale): array
    {
        $sale->loadMissing(['items.staff', 'salePayments', 'client', 'cashier', 'branch', 'booking', 'refunds']);
        $tenant = tenant();
        $qrUrl = $sale->booking ? BookingLinks::review($sale->booking) : url('/book');

        return [
            'sale' => $sale,
            'tenant' => $tenant,
            'branch' => $sale->branch,
            'rtl' => $locale === 'ar',
            'locale' => $locale,
            'qr' => PdfRenderer::qr($qrUrl),
            'qrUrl' => $qrUrl,
            'qrLabel' => $sale->booking ? __('pos.receipt.review', [], $locale) : __('pos.receipt.rebook', [], $locale),
            'logo' => $tenant->brand('logo_path') ? asset('storage/'.$tenant->brand('logo_path')) : null,
            'footer' => $tenant->brand('receipt_footer'),
        ];
    }

    protected function locale(Request $request): string
    {
        $locale = in_array($request->query('locale'), ['en', 'ar'], true) ? $request->query('locale') : app()->getLocale();
        app()->setLocale($locale);

        return $locale;
    }

    protected function authorizePos(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->tenant_id === tenant()?->id && ($user->can('pos.access') || $user->can('reports.view')), 403);
    }
}
