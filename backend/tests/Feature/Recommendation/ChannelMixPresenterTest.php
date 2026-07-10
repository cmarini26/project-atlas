<?php

namespace Tests\Feature\Recommendation;

use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\MarketingChannel;
use App\Models\Opportunity;
use App\Services\Recommendation\ChannelMixPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelMixPresenterTest extends TestCase
{
    use RefreshDatabase;

    private ChannelMixPresenter $presenter;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new ChannelMixPresenter();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_no_decision_yields_empty_channel_mix(): void
    {
        $mix = $this->presenter->present($this->company, null);

        $this->assertSame([], $mix['primary']);
        $this->assertSame([], $mix['supporting']);
        $this->assertSame([], $mix['draft_only']);
        $this->assertSame([], $mix['unavailable']);
    }

    public function test_decision_with_no_channel_ids_yields_empty_executable_buckets(): void
    {
        $decision = $this->makeDecision([]);

        $mix = $this->presenter->present($this->company, $decision);

        $this->assertSame([], $mix['primary']);
        $this->assertSame([], $mix['supporting']);
    }

    public function test_primary_linked_channel_appears_in_primary_bucket(): void
    {
        $channel = $this->makeChannel();
        $this->linkMarketingChannel($channel, ['importance' => 'primary', 'display_name' => 'Acme Instagram']);
        $decision = $this->makeDecision([$channel->id]);

        $mix = $this->presenter->present($this->company, $decision);

        $this->assertCount(1, $mix['primary']);
        $this->assertSame('Acme Instagram', $mix['primary'][0]['name']);
        $this->assertSame([], $mix['supporting']);
    }

    public function test_secondary_linked_channel_appears_in_supporting_bucket(): void
    {
        $channel = $this->makeChannel(['type' => 'facebook']);
        $this->linkMarketingChannel($channel, ['importance' => 'secondary', 'type' => 'facebook', 'display_name' => 'Acme Facebook']);
        $decision = $this->makeDecision([$channel->id]);

        $mix = $this->presenter->present($this->company, $decision);

        $this->assertSame([], $mix['primary']);
        $this->assertCount(1, $mix['supporting']);
        $this->assertSame('Acme Facebook', $mix['supporting'][0]['name']);
    }

    public function test_unlinked_executable_channel_appears_in_supporting_bucket(): void
    {
        $channel = $this->makeChannel(['name' => 'Blog']);
        $decision = $this->makeDecision([$channel->id]);

        $mix = $this->presenter->present($this->company, $decision);

        $this->assertSame([], $mix['primary']);
        $this->assertCount(1, $mix['supporting']);
        $this->assertSame('Blog', $mix['supporting'][0]['name']);
        $this->assertNull($mix['supporting'][0]['marketing_channel']);
    }

    public function test_executable_entry_carries_supports_publishing_from_linked_marketing_channel(): void
    {
        $channel = $this->makeChannel();
        $this->linkMarketingChannel($channel, ['supports_publishing' => true]);
        $decision = $this->makeDecision([$channel->id]);

        $mix = $this->presenter->present($this->company, $decision);

        $this->assertTrue($mix['supporting'][0]['marketing_channel']['supports_publishing']);
    }

    public function test_declared_unlinked_active_channel_is_draft_only(): void
    {
        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'print',
            'display_name' => 'Local Paper Ad',
            'status' => 'active',
            'objective' => ['awareness'],
        ]);

        $mix = $this->presenter->present($this->company, $this->makeDecision([]));

        $this->assertSame([['type' => 'print', 'name' => 'Local Paper Ad']], $mix['draft_only']);
    }

    public function test_inactive_declared_channel_is_unavailable_not_draft_only(): void
    {
        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'x',
            'display_name' => 'Old X Account',
            'status' => 'inactive',
            'objective' => ['awareness'],
        ]);

        $mix = $this->presenter->present($this->company, $this->makeDecision([]));

        $this->assertSame([], $mix['draft_only']);
        $this->assertSame([['type' => 'x', 'name' => 'Old X Account', 'reason' => 'inactive']], $mix['unavailable']);
    }

    public function test_planned_declared_channel_is_unavailable_with_planned_reason(): void
    {
        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'youtube',
            'display_name' => 'Future YouTube',
            'status' => 'planned',
            'objective' => ['awareness'],
        ]);

        $mix = $this->presenter->present($this->company, $this->makeDecision([]));

        $this->assertSame([['type' => 'youtube', 'name' => 'Future YouTube', 'reason' => 'planned']], $mix['unavailable']);
    }

    public function test_inactive_channel_currently_executing_is_not_listed_as_unavailable(): void
    {
        // Edge case: MarketingChannelSelector's empty-set bypass (Phase 6) can
        // leave an inactive-linked channel executable. Don't call it
        // "unavailable" while it's actually running this campaign.
        $channel = $this->makeChannel();
        $this->linkMarketingChannel($channel, ['status' => 'inactive']);
        $decision = $this->makeDecision([$channel->id]);

        $mix = $this->presenter->present($this->company, $decision);

        $this->assertSame([], $mix['unavailable']);
    }

    public function test_is_scoped_to_the_given_company(): void
    {
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'type' => 'print',
            'display_name' => "Other Co's Print Ad",
            'status' => 'active',
            'objective' => ['awareness'],
        ]);

        $mix = $this->presenter->present($this->company, $this->makeDecision([]));

        $this->assertSame([], $mix['draft_only']);
    }

    /** @param list<string> $channelIds */
    private function makeDecision(array $channelIds): Decision
    {
        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'featured_item',
            'title' => 'Test opportunity',
            'description' => 'Test',
            'relevance_score' => 70,
            'timing_score' => 70,
            'confidence_score' => 70,
            'urgency_score' => 40,
            'composite_score' => 80,
            'status' => 'selected',
            'detected_at' => now()->subHour(),
        ]);

        return Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => $channelIds,
            'rationale' => ['why_now' => 'Good timing'],
            'status' => 'recommended',
            'decided_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeChannel(array $overrides = []): Channel
    {
        return Channel::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'is_active' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function linkMarketingChannel(Channel $channel, array $overrides = []): MarketingChannel
    {
        return MarketingChannel::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'channel_id' => $channel->id,
            'type' => $channel->type,
            'display_name' => ucfirst($channel->type),
            'status' => 'active',
            'importance' => 'secondary',
            'is_connected' => true,
            'objective' => ['awareness'],
        ], $overrides));
    }
}
