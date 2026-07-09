<?php

namespace Tests\Feature\MarketingPresence;

use App\Enums\MarketingChannelType;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Integration;
use App\Services\MarketingPresence\MarketingChannelSuggestion;
use App\Services\MarketingPresence\MarketingPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingPresenceSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private MarketingPresenceService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MarketingPresenceService();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_no_suggestions_when_nothing_exists(): void
    {
        $this->assertCount(0, $this->service->suggestChannels($this->company));
    }

    public function test_suggests_website_when_website_integration_exists(): void
    {
        Integration::create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://acme.com'],
            'status' => 'active',
        ]);

        $suggestions = $this->service->suggestChannels($this->company);

        $this->assertCount(1, $suggestions);
        $this->assertInstanceOf(MarketingChannelSuggestion::class, $suggestions->first());
        $this->assertSame(MarketingChannelType::Website, $suggestions->first()->type);
        $this->assertSame('https://acme.com', $suggestions->first()->handleOrUrl);
    }

    public function test_does_not_suggest_website_when_already_declared(): void
    {
        Integration::create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://acme.com'],
            'status' => 'active',
        ]);

        $this->service->declare($this->company, ['type' => 'website', 'display_name' => 'Acme Website']);

        $suggestions = $this->service->suggestChannels($this->company);

        $this->assertCount(0, $suggestions);
    }

    public function test_suggests_channel_types_with_an_equivalent_from_existing_channel_rows(): void
    {
        Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'facebook',
            'name' => 'Acme Facebook',
            'is_active' => true,
        ]);

        $suggestions = $this->service->suggestChannels($this->company);

        $this->assertCount(1, $suggestions);
        $this->assertSame(MarketingChannelType::Facebook, $suggestions->first()->type);
    }

    public function test_does_not_suggest_channel_types_with_no_marketing_channel_type_equivalent(): void
    {
        // 'blog' has no MarketingChannelType equivalent — tryFrom() returns
        // null and it must be filtered out, not throw a ValueError.
        Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'blog',
            'name' => 'Acme Blog',
            'is_active' => true,
        ]);

        $suggestions = $this->service->suggestChannels($this->company);

        $this->assertCount(0, $suggestions);
    }

    public function test_does_not_suggest_inactive_channel_rows(): void
    {
        Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'facebook',
            'name' => 'Acme Facebook',
            'is_active' => false,
        ]);

        $suggestions = $this->service->suggestChannels($this->company);

        $this->assertCount(0, $suggestions);
    }

    public function test_does_not_suggest_a_channel_already_linked_to_a_marketing_channel(): void
    {
        $realChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'facebook',
            'name' => 'Acme Facebook',
            'is_active' => true,
        ]);

        $marketingChannel = $this->service->declare($this->company, ['type' => 'facebook', 'display_name' => 'Facebook']);
        $this->service->link($marketingChannel, $realChannel);

        $suggestions = $this->service->suggestChannels($this->company);

        $this->assertCount(0, $suggestions);
    }

    public function test_does_not_persist_anything(): void
    {
        Integration::create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://acme.com'],
            'status' => 'active',
        ]);
        Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'facebook',
            'name' => 'Acme Facebook',
            'is_active' => true,
        ]);

        $this->service->suggestChannels($this->company);

        $this->assertDatabaseCount('marketing_channels', 0);
    }

    public function test_suggestions_are_scoped_to_the_given_company(): void
    {
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        Channel::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'type' => 'facebook',
            'name' => 'Other Facebook',
            'is_active' => true,
        ]);

        $suggestions = $this->service->suggestChannels($this->company);

        $this->assertCount(0, $suggestions);
    }
}
