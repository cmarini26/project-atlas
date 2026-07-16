<?php

namespace Tests\Feature\Publishing\Email;

use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Enums\EmailAudienceStatus;
use App\Enums\EmailConsentStatus;
use App\Enums\EmailContactSource;
use App\Enums\EmailContactStatus;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\EmailRecipientSnapshot;
use App\Models\Execution;
use App\Models\Opportunity;
use App\Services\Publishing\Email\EmailAudienceService;
use App\Services\Publishing\Email\Exceptions\ContactBelongsToDifferentCompanyException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailAudienceServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailAudienceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(EmailAudienceService::class);
    }

    // ── Contacts ─────────────────────────────────────────────────────────

    public function test_normalized_emails_are_deduplicated_within_one_company(): void
    {
        $company = $this->makeCompany();

        $first = $this->service->addOrReactivateContact($company, 'Alice@Example.com', 'Alice');
        $second = $this->service->addOrReactivateContact($company, 'alice@example.com', 'Alice Updated');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Alice Updated', $second->fresh()->display_name);
        $this->assertDatabaseCount('email_contacts', 1);
    }

    public function test_the_same_email_may_exist_in_two_different_companies(): void
    {
        $companyA = $this->makeCompany('company-a');
        $companyB = $this->makeCompany('company-b');

        $this->service->addOrReactivateContact($companyA, 'shared@example.com');
        $this->service->addOrReactivateContact($companyB, 'shared@example.com');

        $this->assertDatabaseCount('email_contacts', 2);
    }

    public function test_whitespace_and_case_are_normalized(): void
    {
        $company = $this->makeCompany();

        $contact = $this->service->addOrReactivateContact($company, '  Alice@EXAMPLE.com  ');

        $this->assertSame('alice@example.com', $contact->normalized_email);
        $this->assertSame('Alice@EXAMPLE.com', $contact->email);
    }

    public function test_recreating_an_archived_contact_reactivates_the_same_row(): void
    {
        $company = $this->makeCompany();

        $contact = $this->service->addOrReactivateContact($company, 'alice@example.com');
        $this->service->archiveContact($contact);
        $this->assertSame(EmailContactStatus::Archived, $contact->fresh()->status);

        $reactivated = $this->service->addOrReactivateContact($company, 'alice@example.com', 'Alice');

        $this->assertSame($contact->id, $reactivated->id);
        $this->assertSame(EmailContactStatus::Active, $reactivated->status);
        $this->assertDatabaseCount('email_contacts', 1);
    }

    public function test_required_consent_and_source_defaults_are_correct(): void
    {
        $company = $this->makeCompany();

        $contact = $this->service->addOrReactivateContact($company, 'alice@example.com');

        $this->assertSame(EmailContactSource::Manual, $contact->source);
        $this->assertSame(EmailConsentStatus::Unknown, $contact->consent_status);
        $this->assertSame(EmailContactStatus::Active, $contact->status);
    }

    public function test_database_unique_constraint_rejects_a_duplicate_normalized_email(): void
    {
        $company = $this->makeCompany();

        EmailContact::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'email' => 'alice@example.com',
            'normalized_email' => 'alice@example.com',
            'status' => EmailContactStatus::Active,
        ]);

        $this->expectException(QueryException::class);

        EmailContact::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'email' => 'ALICE@example.com',
            'normalized_email' => 'alice@example.com',
            'status' => EmailContactStatus::Active,
        ]);
    }

    // ── Audiences ────────────────────────────────────────────────────────

    public function test_create_rename_and_archive_an_audience(): void
    {
        $company = $this->makeCompany();

        $audience = $this->service->createAudience($company, 'Newsletter');
        $this->assertSame('Newsletter', $audience->name);
        $this->assertSame(EmailAudienceStatus::Active, $audience->status);

        $this->service->renameAudience($audience, 'Monthly Newsletter');
        $this->assertSame('Monthly Newsletter', $audience->fresh()->name);

        $this->service->archiveAudience($audience);
        $this->assertSame('archived', $audience->fresh()->status->value);
        // Archiving never deletes the row.
        $this->assertDatabaseHas('email_audiences', ['id' => $audience->id]);
    }

    public function test_add_and_remove_a_member(): void
    {
        $company = $this->makeCompany();
        $audience = $this->service->createAudience($company, 'Newsletter');
        $contact = $this->service->addOrReactivateContact($company, 'alice@example.com');

        $this->service->addMember($audience, $contact);
        $this->assertSame(1, $audience->fresh()->members()->count());

        $this->service->removeMember($audience, $contact);
        $this->assertSame(0, $audience->fresh()->members()->count());
    }

    public function test_adding_a_member_twice_does_not_duplicate_the_membership_row(): void
    {
        $company = $this->makeCompany();
        $audience = $this->service->createAudience($company, 'Newsletter');
        $contact = $this->service->addOrReactivateContact($company, 'alice@example.com');

        $this->service->addMember($audience, $contact);
        $this->service->addMember($audience, $contact);

        $this->assertDatabaseCount('email_audience_members', 1);
    }

    public function test_cross_company_audience_membership_is_rejected(): void
    {
        $companyA = $this->makeCompany('company-a');
        $companyB = $this->makeCompany('company-b');

        $audience = $this->service->createAudience($companyA, 'Newsletter');
        $contact = $this->service->addOrReactivateContact($companyB, 'alice@example.com');

        $this->expectException(ContactBelongsToDifferentCompanyException::class);

        $this->service->addMember($audience, $contact);
    }

    public function test_audience_size_reflects_current_membership(): void
    {
        $company = $this->makeCompany();
        $audience = $this->service->createAudience($company, 'Newsletter');

        $this->service->addMember($audience, $this->service->addOrReactivateContact($company, 'a@example.com'));
        $this->service->addMember($audience, $this->service->addOrReactivateContact($company, 'b@example.com'));

        $this->assertSame(2, $audience->fresh()->members()->count());
    }

    public function test_empty_audience_has_zero_members(): void
    {
        $company = $this->makeCompany();
        $audience = $this->service->createAudience($company, 'Newsletter');

        $this->assertSame(0, $audience->members()->count());
    }

    // ── Recipient snapshots ──────────────────────────────────────────────

    public function test_snapshot_is_created_from_current_audience_membership(): void
    {
        $company = $this->makeCompany();
        $audience = $this->service->createAudience($company, 'Newsletter');
        $this->service->addMember($audience, $this->service->addOrReactivateContact($company, 'a@example.com'));
        $this->service->addMember($audience, $this->service->addOrReactivateContact($company, 'b@example.com'));

        $campaign = $this->makeCampaign($company, $audience);
        $execution = $this->makeExecution($company, $campaign);

        $snapshots = $this->service->snapshotRecipientsForExecution($execution, $campaign, $audience);

        $this->assertCount(2, $snapshots);
        $this->assertDatabaseCount('email_recipient_snapshots', 2);
    }

    public function test_later_audience_changes_do_not_mutate_an_existing_snapshot(): void
    {
        $company = $this->makeCompany();
        $audience = $this->service->createAudience($company, 'Newsletter');
        $original = $this->service->addOrReactivateContact($company, 'a@example.com');
        $this->service->addMember($audience, $original);

        $campaign = $this->makeCampaign($company, $audience);
        $execution = $this->makeExecution($company, $campaign);
        $this->service->snapshotRecipientsForExecution($execution, $campaign, $audience);

        // Membership changes after the snapshot: remove the original member,
        // add a new one.
        $this->service->removeMember($audience, $original);
        $this->service->addMember($audience, $this->service->addOrReactivateContact($company, 'new@example.com'));

        $snapshotEmails = EmailRecipientSnapshot::withoutGlobalScopes()
            ->where('execution_id', $execution->id)
            ->pluck('email')
            ->all();

        $this->assertSame(['a@example.com'], $snapshotEmails);
    }

    public function test_snapshot_remains_company_scoped(): void
    {
        $companyA = $this->makeCompany('company-a');
        $companyB = $this->makeCompany('company-b');

        $audienceA = $this->service->createAudience($companyA, 'Newsletter A');
        $this->service->addMember($audienceA, $this->service->addOrReactivateContact($companyA, 'a@example.com'));

        $campaignA = $this->makeCampaign($companyA, $audienceA);
        $executionA = $this->makeExecution($companyA, $campaignA);
        $this->service->snapshotRecipientsForExecution($executionA, $campaignA, $audienceA);

        $snapshot = EmailRecipientSnapshot::withoutGlobalScopes()->where('execution_id', $executionA->id)->first();
        $this->assertSame($companyA->id, $snapshot->company_id);
        $this->assertNotSame($companyB->id, $snapshot->company_id);
    }

    public function test_intended_recipient_count_is_correct_via_build_payloads_for_snapshots(): void
    {
        $company = $this->makeCompany();
        $audience = $this->service->createAudience($company, 'Newsletter');
        $this->service->addMember($audience, $this->service->addOrReactivateContact($company, 'a@example.com', 'A'));
        $this->service->addMember($audience, $this->service->addOrReactivateContact($company, 'b@example.com', 'B'));

        $campaign = $this->makeCampaign($company, $audience);
        $execution = $this->makeExecution($company, $campaign);
        $snapshots = $this->service->snapshotRecipientsForExecution($execution, $campaign, $audience);

        $platformPayload = new PlatformPayload(channelType: 'email', data: [
            'subject' => 'Hello',
            'from_name' => 'Atlas',
            'from_email' => 'hello@atlas.test',
            'body' => 'Body',
            'preview_text' => 'Preview',
        ]);

        $payloads = $this->service->buildPayloadsForSnapshots($platformPayload, $snapshots);

        $this->assertCount(2, $payloads);
        $this->assertSame(['a@example.com', 'b@example.com'], $payloads->pluck('toEmail')->sort()->values()->all());
        $this->assertTrue($payloads->every(fn ($p) => $p->subject === 'Hello' && $p->fromEmail === 'hello@atlas.test'));
    }

    private function makeCompany(string $slug = 'test-co'): Company
    {
        return Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => $slug]);
    }

    private function makeCampaign(Company $company, EmailAudience $audience): Campaign
    {
        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'featured_item',
            'title' => 'Test',
            'description' => 'Desc',
            'relevance_score' => 80,
            'timing_score' => 80,
            'confidence_score' => 80,
            'urgency_score' => 80,
            'composite_score' => 80,
            'status' => 'selected',
            'detected_at' => now(),
        ]);

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [],
            'rationale' => [],
            'decided_at' => now(),
        ]);

        return Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'decision_id' => $decision->id,
            'email_audience_id' => $audience->id,
            'campaign_type' => 'featured_item',
            'title' => 'Test Campaign',
            'status' => 'approved',
        ]);
    }

    private function makeExecution(Company $company, Campaign $campaign): Execution
    {
        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);

        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $channel->id,
            'type' => 'email',
            'body' => 'Body.',
            'status' => 'approved',
        ]);

        return Execution::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $channel->id,
            'status' => 'queued',
            'idempotency_key' => Str::ulid()->toString(),
        ]);
    }
}
