<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Billing\Models\Module;
use App\Domain\Branches\Models\Branch;
use App\Domain\Websites\Models\BlogPost;
use App\Domain\Websites\Models\PlatformPage;
use App\Domain\Websites\Models\SitePage;
use App\Http\Controllers\Controller;

/**
 * sitemap.xml and robots.txt for BOTH the platform (central domains) and every salon (tenant domains).
 * Registered once (routes/central/website.php, central-only middleware removed) and dispatched on tenant().
 */
class SeoController extends Controller
{
    public function sitemap()
    {
        $urls = tenant() ? $this->tenantUrls() : $this->platformUrls();

        return response()->view('platform.sitemap', ['urls' => $urls], 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function robots()
    {
        $lines = tenant()
            ? ['User-agent: *', 'Allow: /', 'Disallow: /dashboard', 'Disallow: /pos', 'Disallow: /account', 'Disallow: /studio/looks', 'Disallow: /studio/media', 'Disallow: /payments']
            : ['User-agent: *', 'Allow: /', 'Disallow: /admin'];
        $lines[] = '';
        $lines[] = 'Sitemap: '.url('/sitemap.xml');

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @return list<array{loc:string, lastmod:?string, priority:string, alternates:bool}> */
    protected function platformUrls(): array
    {
        $urls = [];
        $add = function (string $path, string $priority = '0.7', $lastmod = null) use (&$urls) {
            $urls[] = ['loc' => url($path), 'lastmod' => $lastmod?->toAtomString(), 'priority' => $priority, 'alternates' => true];
        };
        $add('/', '1.0');
        $add('/pricing', '0.9');
        $add('/demo', '0.8');
        $add('/signup', '0.8');
        $add('/blog', '0.7');
        $add('/help', '0.6');
        $add('/legal/terms', '0.3');
        $add('/legal/privacy', '0.3');
        foreach (Module::where('is_active', true)->orderBy('sort')->pluck('key') as $key) {
            $add('/features/'.$key, '0.8');
        }
        foreach (BlogPost::published()->get(['slug', 'updated_at']) as $post) {
            $add('/blog/'.$post->slug, '0.6', $post->updated_at);
        }
        $reserved = ['home', 'pricing', 'help', 'legal-terms', 'legal-privacy'];
        foreach (PlatformPage::where('is_published', true)->whereNotIn('slug', $reserved)->where('slug', 'not like', 'features-%')->get(['slug', 'updated_at']) as $page) {
            $add('/p/'.$page->slug, '0.5', $page->updated_at);
        }

        return $urls;
    }

    protected function tenantUrls(): array
    {
        $t = tenant();
        $urls = [['loc' => url('/'), 'lastmod' => $t->updated_at?->toAtomString(), 'priority' => '1.0', 'alternates' => true]];
        foreach (array_filter(['/book' => $t->hasModule('booking'), '/shop' => $t->hasModule('shop'), '/studio' => $t->hasModule('ai_studio'), '/gift-cards' => true]) as $path => $on) {
            $urls[] = ['loc' => url($path), 'lastmod' => null, 'priority' => '0.8', 'alternates' => true];
        }
        foreach (Branch::active()->orderBy('sort')->get(['slug', 'updated_at']) as $branch) {
            $urls[] = ['loc' => url('/branches/'.$branch->slug), 'lastmod' => $branch->updated_at?->toAtomString(), 'priority' => '0.9', 'alternates' => true];
        }
        foreach (SitePage::where('is_published', true)->where('is_home', false)->get(['slug', 'updated_at']) as $page) {
            $urls[] = ['loc' => url('/pages/'.$page->slug), 'lastmod' => $page->updated_at?->toAtomString(), 'priority' => '0.6', 'alternates' => true];
        }

        return $urls;
    }
}
