<?php

namespace App\Console\Commands;

use App\Billing\BillingProfileService;
use App\Models\Company;
use Illuminate\Console\Command;

class GrantBetaBillingAccess extends Command
{
    protected $signature = 'billing:beta-access {company : Company id or slug} {--revoke : Clear the override instead of setting it}';

    protected $description = 'Grant (or revoke) the beta-safe billing access override for a company';

    public function handle(BillingProfileService $profiles): int
    {
        $identifier = (string) $this->argument('company');

        $company = Company::withoutGlobalScopes()
            ->where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if ($company === null) {
            $this->error("No company found for [{$identifier}].");

            return self::FAILURE;
        }

        $enabled = ! $this->option('revoke');
        $profile = $profiles->setBetaAccessOverride($company, $enabled);

        $this->info(sprintf(
            'Beta billing access %s for %s (%s). grants_access=%s',
            $enabled ? 'GRANTED' : 'REVOKED',
            $company->name,
            $company->id,
            $profile->grantsAccess() ? 'true' : 'false',
        ));

        return self::SUCCESS;
    }
}
