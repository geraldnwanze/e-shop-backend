<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Domains\DomainVerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyDomainJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $domainId) {}

    public function handle(DomainVerificationService $verification): void
    {
        // System job: looks the domain up by id across tenants (platform-level operation).
        $domain = Domain::withoutTenantScope()->find($this->domainId);

        if ($domain !== null) {
            $verification->verify($domain);
        }
    }
}
