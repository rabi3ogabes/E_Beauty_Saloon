<?php

namespace App\Support\Pdf;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Mpdf\Mpdf;

/**
 * Renders Blade views to PDF with mPDF (proper Arabic shaping + RTL), used for invoices,
 * receipts, Z-reports and packing slips.
 */
class PdfRenderer
{
    public function render(string $view, array $data = [], ?string $locale = null, string $format = 'A4'): string
    {
        $locale ??= app()->getLocale();
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = view($view, $data + ['rtl' => $locale === 'ar'])->render();
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => $format,
                'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 16,
                'tempDir' => storage_path('app/mpdf'),
                'default_font' => 'dejavusans',
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'directionality' => $locale === 'ar' ? 'rtl' : 'ltr',
            ]);
            $mpdf->SetTitle($data['title'] ?? config('salon.name'));
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } finally {
            app()->setLocale($previous);
        }
    }

    public static function qr(string $payload): string
    {
        return (new QRCode(new QROptions(['outputBase64' => true, 'scale' => 5, 'imageTransparent' => false])))->render($payload);
    }
}
