<?php

namespace App\Domain\AIStudio;

use App\Domain\AIStudio\Actions\ApplyCreditInvoice;
use App\Domain\AIStudio\Contracts\GeneratesMarketingContent;
use App\Domain\AIStudio\Contracts\ModeratesContent;
use App\Domain\AIStudio\Events\AICreditsLow;
use App\Domain\AIStudio\Listeners\HandleLowCredits;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Providers\ProviderFactory;
use App\Domain\AIStudio\Support\BasicModeration;
use App\Domain\Billing\Models\Invoice;
use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Account\AccountSections;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AIStudioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderFactory::class);
        $this->app->bind(GeneratesMarketingContent::class, AIMarketingContentGenerator::class);
        $this->app->bindIf(ModeratesContent::class, BasicModeration::class);
    }

    public function boot(): void
    {
        Client::resolveRelationUsing('looks', fn (Client $c) => $c->hasMany(ClientLook::class)->latest('id'));
        Client::resolveRelationUsing('aiJobs', fn (Client $c) => $c->hasMany(AIJob::class)->latest('id'));
        Tenant::resolveRelationUsing('aiWallet', fn (Tenant $t) => $t->hasOne(AIWallet::class)->withoutGlobalScopes());

        Event::listen(AICreditsLow::class, HandleLowCredits::class);

        // Credits invoices (type "credits") credit the wallet once paid.
        Invoice::updated(function (Invoice $invoice) {
            if ($invoice->type === 'credits' && $invoice->wasChanged('status') && $invoice->status?->value === 'paid') {
                app(ApplyCreditInvoice::class)->handle($invoice);
            }
        });

        app(AccountSections::class)->register(
            'looks',
            fn () => __('ai_studio.account.title'),
            'sparkles',
            fn ($client, $request) => view('site.ai-studio.account', [
                'looks' => ClientLook::where('client_id', $client->id)->where('saved', true)->whereNotNull('after_path')->with('style.service')->latest('id')->get(),
            ])->render(),
            order: 40,
            module: 'ai_studio',
        );
    }
}
