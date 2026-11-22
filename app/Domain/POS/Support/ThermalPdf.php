<?php

namespace App\Domain\POS\Support;

use Mpdf\Mpdf;

/**
 * 80mm receipt PDFs. Same mPDF setup as App\Support\Pdf\PdfRenderer (Arabic shaping, RTL) but with a
 * custom page size and tight margins — PdfRenderer::render() only accepts a named format string.
 */
class ThermalPdf
{
    public function render(string $view, array $data, string $locale, int $widthMm = 80, int $heightMm = 297): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = view($view, $data + ['rtl' => $locale === 'ar'])->render();
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => [$widthMm, $heightMm],
                'margin_left' => 4, 'margin_right' => 4, 'margin_top' => 5, 'margin_bottom' => 5,
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
}
