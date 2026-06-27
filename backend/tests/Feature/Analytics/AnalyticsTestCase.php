<?php

namespace Tests\Feature\Analytics;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class AnalyticsTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Channel $channel;

    protected Campaign $campaign;

    protected Opportunity $opportunity;

    protected Decision $decision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co', 'industry' => 'test',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);

        $this->opportunity = $this->makeOpportunity();

        $this->decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'opportunity_id' => $this->opportunity->id,
            'campaign_type' => 'featured_item', 'channel_ids' => [$this->channel->id],
            'rationale' => ['why_now' => 'Now'],
            'expected_impact' => ['target_engagement_rate' => 0.05],
            'confidence_score' => 70, 'status' => 'recommended', 'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'decision_id' => $this->decision->id,
            'campaign_type' => 'featured_item', 'title' => 'Test Campaign',
            'blueprint' => [
                'channel_strategy' => [['channel' => 'email', 'angle' => 'urgency']],
            ],
            'blueprint_version' => '1.0', 'prompt_version' => '1.0',
            'expected_asset_count' => 1, 'generated_asset_count' => 1, 'status' => 'published',
        ]);
    }

    protected function makeOpportunity(): Opportunity
    {
        return Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'subject_type' => 'company', 'type' => 'featured_item',
            'title' => 'Test', 'description' => 'Desc', 'relevance_score' => 80, 'timing_score' => 80,
            'confidence_score' => 80, 'urgency_score' => 80, 'composite_score' => 80,
            'status' => 'selected', 'detected_at' => now(),
        ]);
    }

    protected function makeExecution(?string $status = 'completed', array $result = ['platform_id' => 'msg-abc']): Execution
    {
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id, 'type' => 'email', 'body' => 'Body.',
            'status' => 'scheduled',
        ]);

        return Execution::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id, 'status' => $status,
            'idempotency_key' => Str::ulid()->toString(), 'completed_at' => now(),
            'result' => $result,
        ]);
    }

    protected function makeCredentials(string $channelType = 'email', string $providerType = 'postmark'): ChannelCredentials
    {
        return ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'channel_type' => $channelType,
            'provider_type' => $providerType,
            'credentials' => json_encode(['api_key' => 'test-key']),
            'status' => 'active',
        ]);
    }
}
