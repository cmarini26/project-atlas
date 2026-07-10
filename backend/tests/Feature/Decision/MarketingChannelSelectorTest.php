<?php

namespace Tests\Feature\Decision;

use App\Models\Channel;
use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\Decision\MarketingChannelSelection;
use App\Services\Decision\MarketingChannelSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingChannelSelectorTest extends TestCase
{
    use RefreshDatabase;

    private MarketingChannelSelector $selector;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->selector = new MarketingChannelSelector();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_returns_a_marketing_channel_selection(): void
    {
        $channel = $this->makeChannel();

        $result = $this->selector->select($this->company, collect([$channel]), collect([$channel]), 'featured_item');

        $this->assertInstanceOf(MarketingChannelSelection::class, $result);
    }

    public function test_channel_with_no_marketing_channel_link_is_neutral(): void
    {
        $channel = $this->makeChannel();

        $result = $this->selector->select($this->company, collect([$channel]), collect([$channel]), 'featured_item');

        $this->assertSame([$channel->id], $result->executableChannelIds);
        $this->assertSame([], $result->excludedChannels);
    }

    public function test_prefers_primary_linked_channel_over_secondary(): void
    {
        $primaryChannel = $this->makeChannel(['type' => 'instagram']);
        $secondaryChannel = $this->makeChannel(['type' => 'facebook']);

        $this->linkMarketingChannel($primaryChannel, ['importance' => 'primary']);
        $this->linkMarketingChannel($secondaryChannel, ['importance' => 'secondary', 'type' => 'facebook']);

        $result = $this->selector->select(
            $this->company,
            collect([$primaryChannel, $secondaryChannel]),
            collect([$primaryChannel, $secondaryChannel]),
            'featured_item',
        );

        $this->assertSame([$primaryChannel->id], $result->executableChannelIds);
    }

    public function test_excludes_inactive_linked_channel_when_an_alternative_exists(): void
    {
        $activeChannel = $this->makeChannel(['type' => 'instagram']);
        $inactiveChannel = $this->makeChannel(['type' => 'facebook']);

        $this->linkMarketingChannel($activeChannel, ['status' => 'active']);
        $this->linkMarketingChannel($inactiveChannel, ['status' => 'inactive', 'type' => 'facebook', 'display_name' => 'Old Facebook']);

        $result = $this->selector->select(
            $this->company,
            collect([$activeChannel, $inactiveChannel]),
            collect([$activeChannel, $inactiveChannel]),
            'featured_item',
        );

        $this->assertSame([$activeChannel->id], $result->executableChannelIds);
        $this->assertSame([['name' => 'Old Facebook', 'reason' => 'linked marketing channel is inactive']], $result->excludedChannels);
    }

    public function test_excludes_planned_linked_channel_from_executable_set(): void
    {
        $activeChannel = $this->makeChannel(['type' => 'instagram']);
        $plannedChannel = $this->makeChannel(['type' => 'facebook']);

        $this->linkMarketingChannel($activeChannel, ['status' => 'active']);
        $this->linkMarketingChannel($plannedChannel, ['status' => 'planned', 'type' => 'facebook', 'display_name' => 'Future Facebook']);

        $result = $this->selector->select(
            $this->company,
            collect([$activeChannel, $plannedChannel]),
            collect([$activeChannel, $plannedChannel]),
            'featured_item',
        );

        $this->assertSame([$activeChannel->id], $result->executableChannelIds);
        $this->assertSame(
            [['name' => 'Future Facebook', 'reason' => 'linked marketing channel is planned, not yet active']],
            $result->excludedChannels,
        );
    }

    public function test_bypasses_exclusion_when_it_would_empty_the_candidate_set(): void
    {
        $onlyChannel = $this->makeChannel(['type' => 'instagram']);
        $this->linkMarketingChannel($onlyChannel, ['status' => 'inactive']);

        $result = $this->selector->select(
            $this->company,
            collect([$onlyChannel]),
            collect([$onlyChannel]),
            'featured_item',
        );

        // Falls back to the pre-Marketing-Presence behavior (all active
        // channels) rather than returning an empty selection.
        $this->assertSame([$onlyChannel->id], $result->executableChannelIds);
        $this->assertSame([], $result->excludedChannels);
    }

    public function test_declared_unlinked_channel_is_reported_as_draft_only(): void
    {
        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'print',
            'display_name' => 'Local Paper Ad',
            'status' => 'active',
            'objective' => ['awareness'],
        ]);

        $channel = $this->makeChannel();

        $result = $this->selector->select($this->company, collect([$channel]), collect([$channel]), 'featured_item');

        $this->assertSame(['Local Paper Ad'], $result->draftOnlyChannels);
        $this->assertSame([$channel->id], $result->executableChannelIds);
    }

    public function test_inactive_unlinked_channel_is_not_reported_as_draft_only(): void
    {
        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'print',
            'display_name' => 'Old Paper Ad',
            'status' => 'inactive',
            'objective' => ['awareness'],
        ]);

        $channel = $this->makeChannel();

        $result = $this->selector->select($this->company, collect([$channel]), collect([$channel]), 'featured_item');

        $this->assertSame([], $result->draftOnlyChannels);
    }

    public function test_a_marketing_channel_never_enters_executable_channel_ids(): void
    {
        // A declared-but-unlinked channel of a type that DOES have a Channel
        // equivalent (instagram) must still never appear in channel_ids —
        // only real Channel rows can.
        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'display_name' => 'Unlinked Instagram',
            'status' => 'active',
            'importance' => 'primary',
            'objective' => ['awareness'],
        ]);

        $channel = $this->makeChannel(['type' => 'email']);

        $result = $this->selector->select($this->company, collect([$channel]), collect([$channel]), 'featured_item');

        $this->assertNotContains('Unlinked Instagram', $result->executableChannelIds);
        $this->assertContainsOnly('string', $result->executableChannelIds);
    }

    public function test_is_scoped_to_the_given_company(): void
    {
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $channel = $this->makeChannel();

        $otherChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'type' => 'instagram',
            'name' => 'Other Instagram',
            'is_active' => true,
        ]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'channel_id' => $otherChannel->id,
            'type' => 'instagram',
            'display_name' => "Other Co's Instagram",
            'status' => 'active',
            'importance' => 'primary',
            'objective' => ['awareness'],
        ]);

        // Company A's channel has no link of its own — the other company's
        // MarketingChannel must never influence this selection.
        $result = $this->selector->select($this->company, collect([$channel]), collect([$channel]), 'featured_item');

        $this->assertSame([$channel->id], $result->executableChannelIds);
        $this->assertSame([], $result->draftOnlyChannels);
    }

    /** @param array<string, mixed> $overrides */
    private function makeChannel(array $overrides = []): Channel
    {
        return Channel::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'type' => 'email',
            'name' => 'Email',
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
