<?php

namespace App\Domain\POS\Http\Middleware;

use App\Http\Middleware\ApplyBranchScope;

/**
 * Tenant route groups run ResolveTenant a second time ('tenant:required'), which resets the branch
 * restrictions ApplyBranchScope set in the web group. Re-apply them for POS routes (a distinct class so
 * Laravel's middleware de-duplication keeps it).
 */
class ReapplyBranchScope extends ApplyBranchScope {}
