<?php

namespace App\Domain\Workspace\Excel;

use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Base for the workspace bulk imports (services, products, clients, staff).
 * Every row is validated on its own; valid rows are imported (create or update), invalid rows are
 * reported with their spreadsheet row number so the owner can fix and re-upload.
 */
abstract class WorkspaceImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    public int $created = 0;

    public int $updated = 0;

    /** @var array<int, list<string>> spreadsheet row number => messages */
    public array $errors = [];

    /** Column keys (snake_case headings) in template order. */
    abstract public static function columns(): array;

    /** Example row for the downloadable template. */
    abstract public static function example(): array;

    /** Rows for the export (same columns). */
    abstract public static function exportRows(): array;

    abstract protected function rules(): array;

    /** @return 'created'|'updated' */
    abstract protected function importRow(array $row): string;

    public function collection(Collection $rows): void
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));

        foreach ($rows as $index => $row) {
            $line = $index + 2; // heading row is 1
            $data = collect($row->toArray())->map(fn ($v) => is_string($v) ? trim($v) : $v)->all();

            $validator = Validator::make($data, $this->rules(), [], $this->attributeNames());
            if ($validator->fails()) {
                $this->errors[$line] = $validator->errors()->all();

                continue;
            }

            try {
                $result = DB::transaction(fn () => $this->importRow($data));
                $result === 'created' ? $this->created++ : $this->updated++;
            } catch (\Throwable $e) {
                $this->errors[$line] = [$e instanceof \DomainException ? $e->getMessage() : __('workspace.excel.row_failed')];
                report($e);
            }
        }
    }

    protected function attributeNames(): array
    {
        return collect(static::columns())->mapWithKeys(fn ($c) => [$c => $c])->all();
    }

    /** "149.50" / 149.5 / "" -> minor units or null. */
    protected function money(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::fromDecimal((string) $value, tenant()?->currency ?? 'QAR')->amount;
    }

    protected function bool(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'yes', 'true', 'y', 'نعم'], true);
    }

    protected static function decimal(?int $minor): ?string
    {
        return $minor === null ? null : Money::of($minor, tenant()?->currency ?? 'QAR')->toDecimal();
    }
}
