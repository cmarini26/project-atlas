<?php

namespace Tests\Feature\Publishing;

use App\Events\CampaignPublished;
use App\Events\ExecutionCompleted;
use App\Jobs\PublishContent;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\Opportunity;
use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\ChannelRendererRegistry;
use App\Services\Publishing\ExecutionService;
use App\Services\Publishing\FakeChannelRenderer;
use App\Services\Publishing\GenericRenderer;
use App\Services\Publishing\LogChannelPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves that the PublishContent → ChannelPublisher → ChannelRenderer chain is exercised.
 */
class RendererIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Channel $channel;

    private Campaign $campaign;

    private FakeChannelRenderer $fakeRenderer;

    private ChannelPublisherRegistry $publisherRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Silver Age',
            'description' => 'Desc',
            'relevance_score' => 80,
            'timing_score' => 75,
            'confidence_score' => 70,
            'urgency_score' => 65,
            'composite_score' => 73,
            'status' => 'selected',
            'detected_at' => now(),
        ]);

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$this->channel->id],
            'rationale' => ['why_now' => 'Now.'],
            'expected_impact' => ['summary' => 'Lift'],
            'confidence_score' => 70,
            'status' => 'recommended',
            'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'featured_item',
            'title' => 'Test Campaign',
            'blueprint' => ['goal' => 'conversion'],
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'approved',
        ]);

        // Wire FakeChannelRenderer into a LogChannelPublisher
        $this->fakeRenderer = new FakeChannelRenderer();
        $rendererRegistry = new ChannelRendererRegistry();
        $rendererRegistry->register($this->fakeRenderer);

        $logPublisher = new LogChannelPublisher($rendererRegistry);
        $this->publisherRegistry = new ChannelPublisherRegistry();
        $this->publisherRegistry->register($logPublisher);

        $this->app->instance(ChannelPublisherRegistry::class, $this->publisherRegistry);
    }

    private function makeExecution(): Execution
    {
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'This is the email body.',
            'status' => 'scheduled',
        ]);

        return Execution::create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id,
            'status' => 'queued',
            'idempotency_key' => Str::ulid()->toString(),
        ]);
    }

    public function test_publish_content_calls_renderer_before_completion(): void
    {
        Event::fake([ExecutionCompleted::class, CampaignPublished::class]);

        $execution = $this->makeExecution();

        $job = new PublishContent($execution);
        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $this->fakeRenderer->assertRendered(1);

        $execution->refresh();
        $this->assertEquals('completed', $execution->status);
    }

    public function test_renderer_receives_correct_asset_and_channel(): void
    {
        Event::fake([ExecutionCompleted::class, CampaignPublished::class]);

        $execution = $this->makeExecution();

        $job = new PublishContent($execution);
        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $rendered = $this->fakeRenderer->renderedItems();
        $this->assertCount(1, $rendered);
        $this->assertEquals($execution->content_asset_id, $rendered[0]['asset']->id);
        $this->assertEquals($this->channel->id, $rendered[0]['channel']->id);
    }

    public function test_renderer_called_once_per_execution(): void
    {
        Event::fake([ExecutionCompleted::class, CampaignPublished::class]);

        // Two separate executions
        $exec1 = $this->makeExecution();

        $asset2 = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'Second email.',
            'status' => 'scheduled',
        ]);
        $exec2 = Execution::create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset2->id,
            'channel_id' => $this->channel->id,
            'status' => 'queued',
            'idempotency_key' => Str::ulid()->toString(),
        ]);

        $service = $this->app->make(ExecutionService::class);
        $registry = $this->app->make(ChannelPublisherRegistry::class);

        (new PublishContent($exec1))->handle($registry, $service);
        (new PublishContent($exec2))->handle($registry, $service);

        $this->fakeRenderer->assertRendered(2);
    }

    public function test_generic_renderer_returns_platform_payload_with_body(): void
    {
        $renderer = $this->app->make(GenericRenderer::class);
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'The body content.',
            'status' => 'scheduled',
        ]);

        $payload = $renderer->render($asset, $this->channel);

        $this->assertEquals('email', $payload->channelType);
        $this->assertEquals('The body content.', $payload->data['body']);
        $this->assertEquals('email', $payload->data['type']);
    }

    public function test_generic_renderer_supports_all_channel_types(): void
    {
        $renderer = $this->app->make(GenericRenderer::class);

        foreach (['email', 'sms', 'blog', 'landing_page', 'facebook', 'instagram', 'linkedin', 'x'] as $type) {
            $this->assertTrue($renderer->supports($type), "GenericRenderer should support {$type}");
        }
    }
}
