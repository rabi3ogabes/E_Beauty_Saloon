<?php

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Tenancy\Domains\DnsResolver;
use App\Domain\Tenancy\Domains\DomainProvisioner;
use App\Domain\Tenancy\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Checks the TXT token + routing record of a custom domain. Scheduled every 10 minutes
 * for unverified domains (see routes/console.php). On success, provisions SSL.
 */
class VerifyCustomDomain implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $domainId) {}

    public function uniqueId(): string
    {
        return 'verify-domain-'.$this->domainId;
    }

    public function handle(DnsResolver $dns, DomainProvisioner $provisioner): void
    {
        $domain = Domain::find($this->domainId);
        if (! $domain || $domain->type !== 'custom') {
            return;
        }

        if (! $domain->isVerified()) {
            $txtOk = in_array($domain->dns_token, $dns->txt('_luxe.'.$domain->domain), true);
            $routeOk = in_array(config('salon.domains.cname_target'), $dns->cname($domain->domain), true)
                || in_array(config('salon.domains.a_record'), $dns->a($domain->domain), true);

            $domain->last_checked_at = now();
            if (! $txtOk || ! $routeOk) {
                $domain->last_error = ! $txtOk ? 'TXT record not found yet' : 'CNAME/A record not pointing to us yet';
                $domain->save();

                return;
            }
            $domain->verified_at = now();
            $domain->last_error = null;
            $domain->save();
            activity()->performedOn($domain)->log('domain.verified');
        }

        if ($domain->ssl_status !== 'active') {
            $domain->update(['ssl_status' => $provisioner->provision($domain)]);
        }
    }
}
