<?php

namespace App\Domain\Insights;

use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\Capacity;
use Illuminate\Support\ServiceProvider;

/** Phase 9 — smart layer: command center, reports, AI insights and operations. */
class InsightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Capacity::class);
        $this->app->bind(ReportService::class);
    }

    public function boot(): void {}
}
