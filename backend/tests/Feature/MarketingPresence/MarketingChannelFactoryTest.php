<?php

namespace Tests\Feature\MarketingPresence;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelStatus;
use App\Enums\MarketingChannelType;
use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingChannelFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_persisted_valid_row(): void
    {
        $channel = MarketingChannel::factory()->create();

        $this->assertDatabaseCount('marketing_channels', 1);
        $this->assertInstanceOf(MarketingChannelType::class, $channel->type);
        $this->assertNotEmpty($channel->display_name);
        $this->assertIsArray($channel->objective);
        $this->assertNotEmpty($channel->objective);
    }

    public function test_factory_auto_creates_a_company_when_not_given(): void
    {
        $channel = MarketingChannel::factory()->create();

        $this->assertDatabaseCount('companies', 1);
        $this->assertSame($channel->company_id, Company::withoutGlobalScopes()->first()->id);
    }

    public function test_factory_accepts_an_explicit_company(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Factory Co', 'slug' => 'factory-co']);

        $channel = MarketingChannel::factory()->create(['company_id' => $company->id]);

        $this->assertSame($company->id, $channel->company_id);
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_factory_default_state_is_active_and_unconnected(): void
    {
        $channel = MarketingChannel::factory()->create();

        $this->assertSame(MarketingChannelStatus::Active, $channel->status);
        $this->assertNull($channel->channel_id);
        $this->assertFalse($channel->is_connected);
        $this->assertFalse($channel->supports_publishing);
        $this->assertFalse($channel->supports_analytics);
    }

    public function test_primary_state_overrides_importance(): void
    {
        $channel = MarketingChannel::factory()->primary()->create();

        $this->assertSame(MarketingChannelImportance::Primary, $channel->importance);
    }

    public function test_inactive_state_overrides_status(): void
    {
        $channel = MarketingChannel::factory()->inactive()->create();

        $this->assertSame(MarketingChannelStatus::Inactive, $channel->status);
    }

    public function test_factory_can_create_many(): void
    {
        MarketingChannel::factory()->count(5)->create();

        $this->assertDatabaseCount('marketing_channels', 5);
    }

    public function test_make_does_not_persist(): void
    {
        MarketingChannel::factory()->make();

        $this->assertDatabaseCount('marketing_channels', 0);
    }
}
