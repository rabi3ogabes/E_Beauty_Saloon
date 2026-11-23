<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Billing\Actions\CalculatePrice;
use App\Domain\Billing\Models\Module;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Websites\Models\BlogPost;
use App\Domain\Websites\Models\Lead;
use App\Domain\Websites\Models\PlatformPage;
use App\Domain\Websites\Support\PlatformContent;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Platform marketing website (central domains): CMS-driven pages with built-in defaults. */
class PlatformPageController extends Controller
{
    public function home()
    {
        return view('platform.home', $this->shared() + ['content' => PlatformContent::page('home')]);
    }

    public function features(string $module)
    {
        $model = Module::where('key', $module)->where('is_active', true)->firstOrFail();
        abort_unless(in_array($module, array_keys(config('salon.modules')), true), 404);

        return view('platform.features', $this->shared() + [
            'module' => $model,
            'content' => PlatformContent::page('features-'.$module),
            'points' => __('platform.features.points.'.$module),
        ]);
    }

    public function pricing()
    {
        return view('platform.pricing', $this->shared() + ['content' => PlatformContent::page('pricing')]);
    }

    /** Live price for the interactive pricing page — the pricing engine is the single source of truth. */
    public function quote(Request $request, CalculatePrice $calculator)
    {
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'slug')->where('is_public', true)->where('is_active', true)],
            'cycle' => ['required', 'integer', Rule::in(config('salon.billing_cycles'))],
            'branches' => ['required', 'integer', 'min:1', 'max:100'],
            'modules' => ['array'],
            'modules.*' => ['string', Rule::in(array_keys(config('salon.modules')))],
        ]);
        $plan = Plan::where('slug', $data['plan'])->firstOrFail();
        try {
            $quote = $calculator->handle($plan, (int) $data['cycle'], (int) $data['branches'], $data['modules'] ?? []);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($quote->toArray() + [
            'plan' => $plan->slug,
            'formatted' => [
                'total' => money($quote->total, $quote->currency),
                'monthly' => money($quote->monthlyEquivalent(), $quote->currency),
                'discount' => money($quote->discountTotal(), $quote->currency),
                'subtotal' => money($quote->subtotal, $quote->currency),
            ],
        ]);
    }

    public function demo()
    {
        return view('platform.demo', $this->shared());
    }

    /** Lead capture → leads (stage "new") for the super admin pipeline. */
    public function storeDemo(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'salon_name' => ['nullable', 'string', 'max:120'],
            'branches' => ['nullable', 'integer', 'min:1', 'max:500'],
            'preferred_time' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['prohibited'], // honeypot
        ]);

        Lead::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'salon_name' => $data['salon_name'] ?? null,
            'branches' => $data['branches'] ?? null,
            'source' => $request->input('source') === 'pricing' ? 'pricing' : 'demo',
            'stage' => 'new',
            'message' => trim(($data['preferred_time'] ?? '') ? __('platform.demo.preferred', ['time' => $data['preferred_time']])."\n".($data['message'] ?? '') : ($data['message'] ?? '')),
        ]);

        return redirect()->route('platform.demo')->with('status', __('platform.demo.thanks'));
    }

    public function blog()
    {
        return view('platform.blog.index', $this->shared() + ['posts' => BlogPost::published()->paginate(9)]);
    }

    public function post(string $slug)
    {
        $post = BlogPost::published()->where('slug', $slug)->firstOrFail();

        return view('platform.blog.show', $this->shared() + [
            'post' => $post,
            'related' => BlogPost::published()->where('id', '!=', $post->id)->limit(3)->get(),
        ]);
    }

    public function help()
    {
        return view('platform.page', $this->shared() + ['content' => PlatformContent::page('help'), 'slug' => 'help']);
    }

    public function legal(string $page)
    {
        abort_unless(in_array($page, ['terms', 'privacy'], true), 404);

        return view('platform.page', $this->shared() + ['content' => PlatformContent::page('legal-'.$page), 'slug' => 'legal-'.$page]);
    }

    /** Custom CMS pages: /p/{slug} */
    public function page(string $slug)
    {
        abort_unless(PlatformPage::published($slug), 404);

        return view('platform.page', $this->shared() + ['content' => PlatformContent::page($slug), 'slug' => $slug]);
    }

    public function signup(Request $request)
    {
        return view('platform.signup', $this->shared() + [
            'selectedPlan' => $request->query('plan', Plan::public()->where('is_featured', true)->value('slug') ?? Plan::public()->value('slug')),
        ]);
    }

    /** Self-serve signup → trial tenant on the chosen plan → owner lands on {slug}.{base}/dashboard/login. */
    public function storeSignup(Request $request, CreateTenant $create)
    {
        $data = $request->validate([
            'salon_name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'alpha_dash', 'min:3', 'max:40', Rule::unique('tenants', 'slug')],
            'owner_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:10', 'max:100'],
            'plan' => ['required', Rule::exists('plans', 'slug')->where('is_public', true)->where('is_active', true)],
            'branches' => ['nullable', 'integer', 'min:1', 'max:100'],
            'terms' => ['accepted'],
            'website' => ['prohibited'],
        ]);

        $plan = Plan::where('slug', $data['plan'])->firstOrFail();
        try {
            $tenant = $create->handle([
                'name' => $data['salon_name'],
                'slug' => $data['slug'] ?: Str::slug($data['salon_name']),
                'owner_name' => $data['owner_name'],
                'owner_email' => $data['email'],
                'owner_phone' => $data['phone'] ?? null,
                'owner_password' => $data['password'],
                'locale' => app()->getLocale(),
            ], ['plan_id' => $plan->id, 'cycle_months' => 1, 'branches' => max((int) ($data['branches'] ?? 1), 1), 'modules' => [], 'trial' => true]);
        } catch (DomainException $e) {
            return back()->withInput($request->except('password'))->withErrors(['salon_name' => $e->getMessage()]);
        }

        Lead::create(['name' => $data['owner_name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'salon_name' => $data['salon_name'],
            'branches' => $data['branches'] ?? 1, 'source' => 'signup', 'stage' => 'won', 'message' => 'Trial: '.$plan->slug]);

        return redirect()->away($tenant->url('dashboard/login?email='.urlencode($data['email'])));
    }

    protected function shared(): array
    {
        return [
            'plans' => Plan::public()->with('billingCycles')->get(),
            'modules' => Module::where('is_active', true)->orderBy('sort')->get(),
        ];
    }
}
