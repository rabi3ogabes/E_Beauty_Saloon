<?php

namespace App\Domain\Workspace\Excel;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Generic one-sheet export used for list exports and import templates. */
class SheetExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function __construct(protected array $headings, protected array $rows) {}

    public function array(): array
    {
        return array_map(fn ($row) => array_map(fn ($key) => $row[$key] ?? null, $this->headings), $this->rows);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    /** @param  class-string<WorkspaceImport>  $import */
    public static function template(string $import): self
    {
        return new self($import::columns(), [$import::example()]);
    }

    /** @param  class-string<WorkspaceImport>  $import */
    public static function of(string $import): self
    {
        return new self($import::columns(), $import::exportRows());
    }
}
