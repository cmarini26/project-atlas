<?php

namespace Tests\Feature\Publishing\Email;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Publishing\EmailRenderer;
use App\Services\Publishing\Exceptions\MalformedPayloadException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailRendererTest extends TestCase
{
    use RefreshDatabase;

    private EmailRenderer $renderer;

    private Company $company;

    private Channel $channel;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new EmailRenderer();

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
            'title' => 'Test',
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
    }

    private function makeAsset(array $overrides = []): ContentAsset
    {
        return ContentAsset::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'title' => 'Email subject from title',
            'body' => 'The email body.',
            'metadata' => [
                'subject_line' => 'Amazing Spider-Man auction — ends Sunday',
                'from_name' => 'CBB Auctions',
                'from_email' => 'auctions@cbbauctions.com',
                'preview_text' => 'Bid before 10pm ET.',
            ],
            'status' => 'approved',
        ], $overrides));
    }

    public function test_renders_email_payload_with_all_fields(): void
    {
        $asset = $this->makeAsset();

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertEquals('email', $payload->channelType);
        $this->assertEquals('Amazing Spider-Man auction — ends Sunday', $payload->data['subject']);
        $this->assertEquals('CBB Auctions', $payload->data['from_name']);
        $this->assertEquals('auctions@cbbauctions.com', $payload->data['from_email']);
        $this->assertEquals('The email body.', $payload->data['body']);
        $this->assertEquals('Bid before 10pm ET.', $payload->data['preview_text']);
    }

    public function test_falls_back_to_asset_title_when_no_subject_line_in_metadata(): void
    {
        $asset = $this->makeAsset([
            'title' => 'Subject from title field',
            'metadata' => [],
        ]);

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertEquals('Subject from title field', $payload->data['subject']);
    }

    public function test_throws_malformed_payload_when_subject_is_missing(): void
    {
        $this->expectException(MalformedPayloadException::class);
        $this->expectExceptionMessage('subject line');

        $asset = $this->makeAsset([
            'title' => null,
            'metadata' => [],
        ]);

        $this->renderer->render($asset, $this->channel);
    }

    public function test_supports_email_channel_type(): void
    {
        $this->assertTrue($this->renderer->supports('email'));
    }

    public function test_does_not_support_other_channel_types(): void
    {
        foreach (['sms', 'instagram', 'facebook', 'blog', 'landing_page', 'linkedin', 'x'] as $type) {
            $this->assertFalse($this->renderer->supports($type), "EmailRenderer should not support {$type}");
        }
    }

    public function test_empty_metadata_fields_become_empty_strings(): void
    {
        $asset = $this->makeAsset([
            'metadata' => ['subject_line' => 'My Subject'],
        ]);

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertEquals('', $payload->data['from_name']);
        $this->assertEquals('', $payload->data['from_email']);
        $this->assertEquals('', $payload->data['preview_text']);
    }
}
