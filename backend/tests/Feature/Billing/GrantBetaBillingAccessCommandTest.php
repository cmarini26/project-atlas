<?php

namespace Tests\Feature\Billing;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrantBetaBillingAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_and_revokes_the_override_by_slug(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->artisan('billing:beta-access', ['company' => 'acme'])
            ->assertExitCode(0)
            ->expectsOutputToContain('GRANTED');

        $this->assertTrue($company->billingProfile()->first()->beta_access_override);

        $this->artisan('billing:beta-access', ['company' => $company->id, '--revoke' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('REVOKED');

        $this->assertFalse($company->billingProfile()->first()->beta_access_override);
    }

    public function test_it_fails_for_an_unknown_company(): void
    {
        $this->artisan('billing:beta-access', ['company' => 'nope'])
            ->assertExitCode(1)
            ->expectsOutputToContain('No company found');
    }
}
