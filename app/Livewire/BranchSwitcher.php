<?php

namespace App\Livewire;

use App\Domain\Branches\Models\Branch;
use Livewire\Component;

/** Top-bar branch switcher: "All branches" or one branch; every list & report respects it. */
class BranchSwitcher extends Component
{
    public ?int $branchId = null;

    public function mount(): void
    {
        $this->branchId = session('branch_id');
    }

    public function updatedBranchId($value): void
    {
        $value = $value ? (int) $value : null;
        $allowed = auth()->user()?->restrictedBranchIds();
        if ($value && $allowed !== null && ! in_array($value, $allowed, true)) {
            return;
        }
        session(['branch_id' => $value]);
        $this->redirect(request()->header('Referer') ?? url('/dashboard'), navigate: true);
    }

    public function render()
    {
        $branches = Branch::query()->withoutGlobalScope('allowed_branches')
            ->when(auth()->user()?->restrictedBranchIds(), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('sort')->get(['id', 'name']);

        return view('livewire.branch-switcher', ['branches' => $branches, 'canSeeAll' => auth()->user()?->restrictedBranchIds() === null]);
    }
}
