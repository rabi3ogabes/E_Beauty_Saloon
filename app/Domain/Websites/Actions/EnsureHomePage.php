<?php

namespace App\Domain\Websites\Actions;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Websites\Models\SitePage;
use App\Domain\Websites\Support\SectionRegistry;
use Illuminate\Support\Facades\DB;

/** Materializes the designed default home page so the owner can edit it (or resets it). */
class EnsureHomePage
{
    public function handle(Tenant $tenant, bool $reset = false): SitePage
    {
        return DB::transaction(function () use ($tenant, $reset) {
            $page = SitePage::firstOrCreate(['is_home' => true], [
                'slug' => 'home',
                'title' => ['en' => 'Home', 'ar' => 'الرئيسية'],
                'is_published' => true,
            ]);
            if ($reset) {
                $page->sections()->delete();
            }
            if ($page->sections()->doesntExist()) {
                foreach (SectionRegistry::defaultHome($tenant) as $i => $section) {
                    $page->sections()->create([
                        'type' => $section->type, 'content' => $section->content, 'settings' => $section->settings ?: [], 'is_visible' => true, 'sort' => $i,
                    ]);
                }
            }

            return $page->load('sections');
        });
    }
}
