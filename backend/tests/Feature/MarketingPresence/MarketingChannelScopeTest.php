<?php

namespace Tests\Feature\MarketingPresence;

use App\Models\Channel;
use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingChannelScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Scope Co',
            'slug' => 'scope-co',
        ]);
    }

    public function test_active_scope_returns_only_active_status(): void
    {
        $this->makeChannel(['status' => 'active', 'display_name' => 'Active One']);
        $this->makeChannel(['status' => 'inactive', 'display_name' => 'Inactive One']);
        $this->makeChannel(['status' => 'planned', 'display_name' => 'Planned One']);

        $results = MarketingChannel::active()->get();

        $this->assertCount(1, $results);
        $this->assertSame('Active One', $results->first()->display_name);
    }

    public function test_primary_scope_returns_only_primary_importance(): void
    {
        $this->makeChannel(['importance' => 'primary', 'display_name' => 'Primary One']);
        $this->makeChannel(['importance' => 'secondary', 'display_name' => 'Secondary One']);
        $this->makeChannel(['importance' => 'experimental', 'display_name' => 'Experimental One']);

        $results = MarketingChannel::primary()->get();

        $this->assertCount(1, $results);
        $this->assertSame('Primary One', $results->first()->display_name);
    }

    public function test_connected_scope_requires_both_channel_id_and_is_connected(): void
    {
        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'is_active' => true,
        ]);

        // Linked but not marked connected — must be excluded.
        $this->makeChannel(['channel_id' => $channel->id, 'is_connected' => false, 'display_name' => 'Linked Not Connected']);

        // Marked connected but no channel_id — must be excluded (declared-only, per spec §9).
        $this->makeChannel(['channel_id' => null, 'is_connected' => true, 'display_name' => 'Connected Flag Only']);

        // Both — must be included.
        $this->makeChannel(['channel_id' => $channel->id, 'is_connected' => true, 'display_name' => 'Truly Connected']);

        $results = MarketingChannel::connected()->get();

        $this->assertCount(1, $results);
        $this->assertSame('Truly Connected', $results->first()->display_name);
    }

    public function test_scopes_can_be_combined(): void
    {
        $this->makeChannel(['status' => 'active', 'importance' => 'primary', 'display_name' => 'Match']);
        $this->makeChannel(['status' => 'active', 'importance' => 'secondary', 'display_name' => 'No Match Importance']);
        $this->makeChannel(['status' => 'inactive', 'importance' => 'primary', 'display_name' => 'No Match Status']);

        $results = MarketingChannel::active()->primary()->get();

        $this->assertCount(1, $results);
        $this->assertSame('Match', $results->first()->display_name);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeChannel(array $overrides = []): MarketingChannel
    {
        return MarketingChannel::create(array_merge([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'display_name' => 'Test Channel',
            'objective' => ['awareness'],
        ], $overrides));
    }
}
