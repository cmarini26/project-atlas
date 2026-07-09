<?php

namespace Tests\Feature\MarketingPresence;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelObjective;
use App\Enums\MarketingChannelStatus;
use App\Enums\MarketingChannelType;
use App\Enums\PostingFrequency;
use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingChannelModelTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Model Test Co',
            'slug' => 'model-test-co',
        ]);
    }

    public function test_fillable_attributes_can_be_mass_assigned(): void
    {
        $channel = MarketingChannel::create([
            'company_id' => $this->company->id,
            'channel_id' => null,
            'type' => 'instagram',
            'display_name' => 'CBB Auctions Instagram',
            'handle_or_url' => '@cbbauctions',
            'status' => 'active',
            'importance' => 'primary',
            'objective' => ['awareness', 'community'],
            'audience' => 'Comic book collectors',
            'posting_frequency' => 'weekly',
            'notes' => 'Managed by the owner directly',
            'is_connected' => false,
            'supports_publishing' => false,
            'supports_analytics' => false,
            'metadata' => ['follower_count' => 4200],
        ]);

        $this->assertDatabaseHas('marketing_channels', [
            'id' => $channel->id,
            'display_name' => 'CBB Auctions Instagram',
            'handle_or_url' => '@cbbauctions',
        ]);
    }

    public function test_enum_columns_cast_to_backed_enum_instances(): void
    {
        $channel = $this->makeChannel()->refresh();

        $this->assertInstanceOf(MarketingChannelType::class, $channel->type);
        $this->assertInstanceOf(MarketingChannelStatus::class, $channel->status);
        $this->assertInstanceOf(MarketingChannelImportance::class, $channel->importance);
        $this->assertInstanceOf(PostingFrequency::class, $channel->posting_frequency);
    }

    public function test_objective_and_metadata_cast_to_array(): void
    {
        $channel = $this->makeChannel([
            'objective' => ['leads', 'sales'],
            'metadata' => ['venue_name' => 'Downtown Convention Center'],
        ]);

        $channel->refresh();

        $this->assertIsArray($channel->objective);
        $this->assertSame(['leads', 'sales'], $channel->objective);
        $this->assertIsArray($channel->metadata);
        $this->assertSame('Downtown Convention Center', $channel->metadata['venue_name']);
    }

    public function test_boolean_flags_cast_to_bool(): void
    {
        $channel = $this->makeChannel(['is_connected' => 1, 'supports_publishing' => 0, 'supports_analytics' => 1]);
        $channel->refresh();

        $this->assertTrue($channel->is_connected);
        $this->assertFalse($channel->supports_publishing);
        $this->assertTrue($channel->supports_analytics);
    }

    public function test_default_values_apply_when_not_specified(): void
    {
        $channel = MarketingChannel::create([
            'company_id' => $this->company->id,
            'type' => 'other',
            'display_name' => 'Local Radio Ad',
            'objective' => ['awareness'],
        ]);

        $channel->refresh();

        $this->assertSame(MarketingChannelStatus::Active, $channel->status);
        $this->assertSame(MarketingChannelImportance::Secondary, $channel->importance);
        $this->assertSame(PostingFrequency::Unknown, $channel->posting_frequency);
        $this->assertFalse($channel->is_connected);
        $this->assertFalse($channel->supports_publishing);
        $this->assertFalse($channel->supports_analytics);
        $this->assertNull($channel->channel_id);
    }

    public function test_nullable_fields_accept_null(): void
    {
        $channel = $this->makeChannel([
            'handle_or_url' => null,
            'audience' => null,
            'notes' => null,
            'metadata' => null,
        ]);

        $channel->refresh();

        $this->assertNull($channel->handle_or_url);
        $this->assertNull($channel->audience);
        $this->assertNull($channel->notes);
        $this->assertNull($channel->metadata);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeChannel(array $overrides = []): MarketingChannel
    {
        return MarketingChannel::create(array_merge([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'display_name' => 'Test Channel',
            'objective' => [MarketingChannelObjective::Awareness->value],
        ], $overrides));
    }
}
