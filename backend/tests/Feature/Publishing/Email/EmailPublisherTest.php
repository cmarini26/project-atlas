<?php

namespace Tests\Feature\Publishing\Email;

use App\Events\CampaignPublished;
use App\Events\ExecutionCompleted;
use App\Jobs\PublishContent;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\Opportunity;
use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\ChannelRendererRegistry;
use App\Services\Publishing\Email\EmailProviderRegistry;
use App\Services\Publishing\Email\FakeEmailProvider;
use App\Services\Publishing\EmailPublisher;
use App\Services\Publishing\EmailRenderer;
use App\Services\Publishing\Exceptions\AuthenticationException;
use App\Services\Publishing\Exceptions\ContentPolicyViolationException;
use App\Services\Publishing\Exceptions\CredentialsNotFoundException;
use App\Services\Publishing\ExecutionService;
use App\Services\Publishing\GenericRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailPublisherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Channel $channel;

    private Campaign $campaign;

    private FakeEmailProvider $fakeProvider;

    private EmailPublisher $publisher;

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

        // Wire FakeEmailProvider into EmailPublisher
        $this->fakeProvider = new FakeEmailProvider();
        $emailProviderRegistry = new EmailProviderRegistry();
        $emailProviderRegistry->register($this->fakeProvider);

        $rendererRegistry = new ChannelRendererRegistry();
        $rendererRegistry->register(new EmailRenderer());
        $rendererRegistry->register(new GenericRenderer());

        $this->publisher = $this->app->make(EmailPublisher::class, [
            'renderers' => $rendererRegistry,
            'emailProviders' => $emailProviderRegistry,
        ]);

        // Bind EmailPublisher into ChannelPublisherRegistry for integration tests
        $publisherRegistry = new ChannelPublisherRegistry();
        $publisherRegistry->register($this->publisher);
        $this->app->instance(ChannelPublisherRegistry::class, $publisherRegistry);
    }

    private function makeCredentials(array $overrides = []): ChannelCredentials
    {
        return ChannelCredentials::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'channel_type' => 'email',
            'provider_type' => 'fake',
            'credentials' => json_encode(['from_address' => 'auctions@cbbauctions.com', 'from_name' => 'CBB Auctions']),
            'status' => 'active',
        ], $overrides));
    }

    private function makeAsset(array $overrides = []): ContentAsset
    {
        return ContentAsset::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'title' => 'Fallback Subject',
            'body' => 'Email body content.',
            'metadata' => [
                'subject_line' => 'ASM #1 CGC 7.5 — ends Sunday',
                'from_name' => 'CBB Auctions',
                'from_email' => 'auctions@cbbauctions.com',
                'preview_text' => 'Bid before 10pm ET.',
            ],
            'status' => 'scheduled',
        ], $overrides));
    }

    private function makeExecution(ContentAsset $asset): Execution
    {
        return Execution::create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id,
            'status' => 'queued',
            'idempotency_key' => Str::ulid()->toString(),
        ]);
    }

    // ── publish() ────────────────────────────────────────────────────────────

    public function test_publish_sends_email_via_provider(): void
    {
        $this->makeCredentials();
        $asset = $this->makeAsset();
        $execution = $this->makeExecution($asset);

        $result = $this->publisher->publish($execution);

        $this->fakeProvider->assertSent(1);
        $this->assertStringStartsWith('fake-email-', $result->platformId);
    }

    public function test_publish_passes_correct_subject_to_provider(): void
    {
        $this->makeCredentials();
        $asset = $this->makeAsset();
        $execution = $this->makeExecution($asset);

        $this->publisher->publish($execution);

        $sent = $this->fakeProvider->sentItems();
        $this->assertCount(1, $sent);
        $this->assertEquals('ASM #1 CGC 7.5 — ends Sunday', $sent[0]['payload']->subject);
    }

    public function test_publish_returns_execution_result_with_email_metadata(): void
    {
        $this->makeCredentials();
        $asset = $this->makeAsset();
        $execution = $this->makeExecution($asset);

        $result = $this->publisher->publish($execution);

        $this->assertEquals('email', $result->metadata['publisher']);
        $this->assertArrayHasKey('subject', $result->metadata);
        $this->assertNotNull($result->publishedAt);
    }

    public function test_publish_uses_message_id_from_provider_as_platform_id(): void
    {
        $this->makeCredentials();
        $this->fakeProvider->queueMessageId('msg-12345-abc');
        $asset = $this->makeAsset();
        $execution = $this->makeExecution($asset);

        $result = $this->publisher->publish($execution);

        $this->assertEquals('msg-12345-abc', $result->platformId);
    }

    public function test_publish_propagates_non_retryable_provider_exception(): void
    {
        $this->makeCredentials();
        $this->fakeProvider->queueFailure(new ContentPolicyViolationException('Policy'));
        $asset = $this->makeAsset();
        $execution = $this->makeExecution($asset);

        $this->expectException(ContentPolicyViolationException::class);

        $this->publisher->publish($execution);
    }

    public function test_publish_throws_credentials_not_found_when_no_credentials(): void
    {
        // No credentials created for this company
        $asset = $this->makeAsset();
        $execution = $this->makeExecution($asset);

        $this->expectException(CredentialsNotFoundException::class);

        $this->publisher->publish($execution);
    }

    public function test_publish_throws_authentication_exception_for_error_status_credentials(): void
    {
        $this->makeCredentials(['status' => 'error']);
        $asset = $this->makeAsset();
        $execution = $this->makeExecution($asset);

        $this->expectException(AuthenticationException::class);

        $this->publisher->publish($execution);
    }

    public function test_supports_email_channel_type_only(): void
    {
        $this->assertTrue($this->publisher->supports('email'));

        foreach (['sms', 'instagram', 'facebook', 'blog', 'linkedin', 'x', 'landing_page'] as $type) {
            $this->assertFalse($this->publisher->supports($type), "EmailPublisher should not support {$type}");
        }
    }

    public function test_ping_delegates_to_email_provider(): void
    {
        $credentials = $this->makeCredentials();

        $result = $this->publisher->ping($credentials);

        $this->assertTrue($result->reachable);
    }

    // ── Full pipeline integration ─────────────────────────────────────────────

    public function test_publish_content_job_uses_email_publisher_for_email_channel(): void
    {
        Event::fake([ExecutionCompleted::class, CampaignPublished::class]);

        $this->makeCredentials();
        $asset = $this->makeAsset();
        $execution = $this->makeExecution($asset);

        $job = new PublishContent($execution);
        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $this->fakeProvider->assertSent(1);

        $execution->refresh();
        $this->assertEquals('completed', $execution->status);
        $this->assertStringStartsWith('fake-email-', $execution->result['platform_id']);
    }

    public function test_execution_result_metadata_includes_provider_and_subject(): void
    {
        Event::fake([ExecutionCompleted::class, CampaignPublished::class]);

        $this->makeCredentials(['provider_type' => 'fake']);
        $asset = $this->makeAsset();
        $execution = $this->makeExecution($asset);

        $job = new PublishContent($execution);
        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $execution->refresh();
        $this->assertEquals('email', $execution->result['metadata']['publisher']);
        $this->assertEquals('fake', $execution->result['metadata']['provider']);
        $this->assertEquals('ASM #1 CGC 7.5 — ends Sunday', $execution->result['metadata']['subject']);
    }
}
