<?php

namespace App\Domain\Branches\Actions;

use App\Domain\Branches\Models\Branch;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Str;

/** Creates a branch, enforcing the subscription's branch limit. */
class CreateBranch
{
    public function handle(array $data): Branch
    {
        $tenant = tenant() ?? throw new \RuntimeException('No tenant');
        abort_if($tenant->isReadOnly(), 423, __('salon.errors.read_only'));

        if (static::atLimit()) {
            throw new DomainException(__('salon.errors.branch_limit', ['limit' => $tenant->branchLimit()]));
        }

        $name = (array) ($data['name'] ?? []);
        $data['slug'] = $data['slug'] ?? static::uniqueSlug(Str::slug($name['en'] ?? reset($name) ?: 'branch'));
        $data['opening_hours'] ??= Branch::defaultHours();
        $data['timezone'] ??= $tenant->timezone ?? 'Asia/Qatar';

        $branch = Branch::create($data);
        activity()->performedOn($branch)->causedBy(auth()->user())->log('branch.created');

        return $branch;
    }

    public static function count(): int
    {
        return Branch::query()->withoutGlobalScope('allowed_branches')->count();
    }

    public static function atLimit(): bool
    {
        return static::count() >= (tenant()?->branchLimit() ?? 1);
    }

    protected static function uniqueSlug(string $base): string
    {
        $slug = $base ?: 'branch';
        $i = 2;
        while (Branch::withTrashed()->withoutGlobalScope('allowed_branches')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
