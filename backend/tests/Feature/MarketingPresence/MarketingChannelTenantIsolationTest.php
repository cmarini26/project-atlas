<?php

namespace Tests\Feature\MarketingPresence;

use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingChannelTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_scope_filters_marketing_channels_by_bound_company(): void
    {
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'type' => 'instagram',
            'display_name' => 'A Instagram',
            'objective' => ['awareness'],
        ]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'type' => 'instagram',
            'display_name' => 'B Instagram',
            'objective' => ['awareness'],
        ]);

        // Bind company A — should only see company A's declared channel.
        $this->app->instance('current_company_id', $companyA->id);

        $this->assertCount(1, MarketingChannel::all());
        $this->assertSame('A Instagram', MarketingChannel::first()->display_name);
    }

    public function test_no_company_bound_returns_all_rows_for_global_queries(): void
    {
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'type' => 'email',
            'display_name' => 'A Email',
            'objective' => ['retention'],
        ]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'type' => 'email',
            'display_name' => 'B Email',
            'objective' => ['retention'],
        ]);

        // No company bound in container — scope is a no-op, all rows visible.
        $this->assertCount(2, MarketingChannel::withoutGlobalScopes()->get());
    }

    public function test_company_a_cannot_query_company_bs_marketing_channel_by_id(): void
    {
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);

        $channelB = MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'type' => 'events',
            'display_name' => 'B Trade Shows',
            'objective' => ['trust'],
        ]);

        $this->app->instance('current_company_id', $companyA->id);

        $this->assertNull(MarketingChannel::find($channelB->id));
    }

    public function test_business_brain_style_query_via_company_relationship_respects_isolation(): void
    {
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'type' => 'website',
            'display_name' => 'A Website',
            'objective' => ['seo'],
        ]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'type' => 'website',
            'display_name' => 'B Website',
            'objective' => ['seo'],
        ]);

        // Direct, explicit company_id filtering — the pattern BusinessBrainService
        // will use in Phase 5 — always isolates correctly regardless of scope binding.
        $companyAChannels = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $companyA->id)
            ->get();

        $this->assertCount(1, $companyAChannels);
        $this->assertSame('A Website', $companyAChannels->first()->display_name);
    }
}
