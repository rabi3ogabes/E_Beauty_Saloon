<?php

namespace App\Domain\Insights\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Generic multi-sheet workbook for report pages.
 * $sections: list<array{title: string, headings: list<string>, rows: list<list<scalar>>}> (values already formatted).
 */
class ReportExport implements Export, WithMultipleSheets
{
    public function __construct(public readonly array $sections) {}

    public function sheets(): array
    {
        return array_map(fn (array $s) => new class($s) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            public function __construct(protected array $section) {}

            public function array(): array
            {
                return $this->section['rows'];
            }

            public function headings(): array
            {
                return $this->section['headings'];
            }

            public function title(): string
            {
                return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $this->section['title']), 0, 31);
            }
        }, $this->sections ?: [['title' => 'Report', 'headings' => [], 'rows' => []]]);
    }
}
