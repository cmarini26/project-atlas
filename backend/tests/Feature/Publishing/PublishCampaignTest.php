<?php

namespace Tests\Feature\Publishing;

use App\Jobs\PublishCampaign;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\Opportunity;
use App\Services\Publishing\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishCampaignTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Channel $channel;

    private Campaign $campaign;

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
            'confidence_score' => 70,
            'status' => 'recommended',
            'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'featured_item',
            'title' => 'Test Campaign',
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'draft',
        ]);
    }

    private function makeApprovedAsset(): ContentAsset
    {
        return ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'Email body.',
            'status' => 'approved',
        ]);
    }

    public function test_an_unapproved_campaign_is_never_queued_or_sent(): void
    {
        // status stays 'draft' — never went through
        // RecommendationController::approve() -> ApprovalService::approve().
        $audience = EmailAudience::create(['company_id' => $this->company->id, 'name' => 'Newsletter', 'status' => 'active']);
        $contact = EmailContact::create(['company_id' => $this->company->id, 'email' => 'a@example.com', 'normalized_email' => 'a@example.com']);
        $audience->members()->attach($contact->id);
        $this->campaign->update(['email_audience_id' => $audience->id]);
        $this->makeApprovedAsset();

        $job = new PublishCampaign($this->campaign);
        $job->handle($this->app->make(ExecutionService::class));

        // Human approval must remain required before any external send:
        // no Execution, and therefore no recipient snapshot, is ever
        // created for a campaign that was never approved.
        $this->assertDatabaseCount('executions', 0);
        $this->assertDatabaseCount('email_recipient_snapshots', 0);
    }

    public function test_an_approved_campaign_is_queued_and_snapshots_its_audience(): void
    {
        $this->campaign->update(['status' => 'approved']);
        $audience = EmailAudience::create(['company_id' => $this->company->id, 'name' => 'Newsletter', 'status' => 'active']);
        $contact = EmailContact::create(['company_id' => $this->company->id, 'email' => 'a@example.com', 'normalized_email' => 'a@example.com']);
        $audience->members()->attach($contact->id);
        $this->campaign->update(['email_audience_id' => $audience->id]);
        $this->makeApprovedAsset();

        $job = new PublishCampaign($this->campaign);
        $job->handle($this->app->make(ExecutionService::class));

        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('email_recipient_snapshots', 1);
        $this->assertDatabaseHas('email_recipient_snapshots', ['email' => 'a@example.com']);
    }
}
