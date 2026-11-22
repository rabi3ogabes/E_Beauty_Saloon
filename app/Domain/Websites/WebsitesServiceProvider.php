<?php

namespace App\Domain\Websites;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Websites\Contracts\WebPushSender;
use App\Domain\Websites\Models\SitePage;
use App\Domain\Websites\Support\NullWebPushSender;
use Illuminate\Support\ServiceProvider;

class WebsitesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(WebPushSender::class, NullWebPushSender::class);
    }

    public function boot(): void
    {
        Tenant::resolveRelationUsing('sitePages', fn (Tenant $t) => $t->hasMany(SitePage::class)->withoutGlobalScopes());
    }
}
