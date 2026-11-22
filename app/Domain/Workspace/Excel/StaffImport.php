<?php

namespace App\Domain\Workspace\Excel;

use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Models\Staff;

/** Upserts staff by email (or English name when no email); branches = comma separated branch slugs. */
class StaffImport extends WorkspaceImport
{
    public static function columns(): array
    {
        return ['name_en', 'name_ar', 'title_en', 'title_ar', 'email', 'phone', 'color', 'bookable', 'branches'];
    }

    public static function example(): array
    {
        return ['name_en' => 'Lina Haddad', 'name_ar' => 'لينا حداد', 'title_en' => 'Senior Stylist', 'title_ar' => 'مصففة أولى',
            'email' => 'lina@example.com', 'phone' => '+97455001122', 'color' => '#C8A96A', 'bookable' => 'yes', 'branches' => 'main'];
    }

    public static function exportRows(): array
    {
        return Staff::query()->withoutGlobalScope('branch_visibility')->with('branches')->orderBy('sort')->get()->map(fn (Staff $s) => [
            'name_en' => $s->getTranslation('name', 'en', false), 'name_ar' => $s->getTranslation('name', 'ar', false),
            'title_en' => $s->getTranslation('title', 'en', false), 'title_ar' => $s->getTranslation('title', 'ar', false),
            'email' => $s->email, 'phone' => $s->phone, 'color' => $s->color, 'bookable' => $s->is_bookable ? 'yes' : 'no',
            'branches' => $s->branches->pluck('slug')->join(','),
        ])->all();
    }

    protected function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    protected function importRow(array $row): string
    {
        $query = Staff::query()->withoutGlobalScope('branch_visibility');
        $staff = filled($row['email'] ?? null)
            ? $query->where('email', $row['email'])->first()
            : $query->where('name->en', $row['name_en'])->first();
        $exists = (bool) $staff;
        $staff ??= new Staff;

        $staff->fill([
            'name' => array_filter(['en' => $row['name_en'], 'ar' => $row['name_ar'] ?? null]),
            'title' => array_filter(['en' => $row['title_en'] ?? null, 'ar' => $row['title_ar'] ?? null]) ?: null,
            'email' => $row['email'] ?? $staff->email,
            'phone' => $row['phone'] ?? $staff->phone,
            'color' => $row['color'] ?? ($staff->color ?? '#C8A96A'),
            'is_bookable' => $this->bool($row['bookable'] ?? null),
        ]);
        $staff->save();

        if (filled($row['branches'] ?? null)) {
            $slugs = array_filter(array_map('trim', explode(',', (string) $row['branches'])));
            $staff->branches()->sync(Branch::query()->whereIn('slug', $slugs)->pluck('id')->all());
        }

        return $exists ? 'updated' : 'created';
    }
}
