<?php

namespace Tests\Feature\Discovery;

use App\Enums\EmailConsentStatus;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\Integration;
use App\Services\Observatory\Connectors\Mailchimp\MailchimpApiClient;
use App\Services\Observatory\Connectors\Mailchimp\MailchimpConnector;
use App\Services\Publishing\Email\EmailAudienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MailchimpConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_imports_subscribed_members_into_an_atlas_audience(): void
    {
        $client = Mockery::mock(MailchimpApiClient::class);
        $client->expects('fetchAudience')->once()->with('us14', 'mc-key', 'aud-123')->andReturn([
            'id' => 'aud-123',
            'name' => 'Newsletter',
            'stats' => ['member_count' => 2],
        ]);
        $client->expects('fetchMembers')->once()->with('us14', 'mc-key', 'aud-123')->andReturn([
            [
                'email_address' => 'hello@example.com',
                'status' => 'subscribed',
                'merge_fields' => ['FNAME' => 'Hello', 'LNAME' => 'World'],
            ],
            [
                'email_address' => 'nope@example.com',
                'status' => 'unsubscribed',
                'merge_fields' => ['FNAME' => 'Nope', 'LNAME' => 'User'],
            ],
        ]);

        $company = Company::factory()->create();
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'mailchimp',
            'name' => 'Mailchimp',
            'config' => ['server_prefix' => 'us14', 'api_key' => 'mc-key', 'audience_id' => 'aud-123'],
            'status' => 'active',
        ]);

        $connector = new MailchimpConnector($client, app(EmailAudienceService::class));
        $results = $connector->sync($integration);

        $this->assertCount(1, $results);

        $audience = EmailAudience::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $this->assertNotNull($audience);
        $this->assertSame('Newsletter', $audience->name);

        $subscribed = EmailContact::withoutGlobalScopes()->where('company_id', $company->id)->where('email', 'hello@example.com')->first();
        $unsubscribed = EmailContact::withoutGlobalScopes()->where('company_id', $company->id)->where('email', 'nope@example.com')->first();

        $this->assertNotNull($subscribed);
        $this->assertSame(EmailConsentStatus::Confirmed, $subscribed->consent_status);
        $this->assertTrue($audience->members()->where('email_contacts.id', $subscribed->id)->exists());

        $this->assertNotNull($unsubscribed);
        $this->assertSame(EmailConsentStatus::Declined, $unsubscribed->consent_status);
        $this->assertFalse($audience->members()->where('email_contacts.id', $unsubscribed->id)->exists());

        $payload = json_decode($results->first()->payload, true);
        $this->assertSame(1, $payload['imported_contacts']);
        $this->assertSame(1, $payload['non_sendable_contacts']);
    }

    public function test_supports_only_mailchimp_integrations(): void
    {
        $connector = new MailchimpConnector(Mockery::mock(MailchimpApiClient::class), app(EmailAudienceService::class));
        $company = Company::factory()->create();

        $mailchimp = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'mailchimp',
            'name' => 'Mailchimp',
            'config' => ['server_prefix' => 'us14', 'api_key' => 'key', 'audience_id' => 'aud'],
            'status' => 'active',
        ]);

        $instagram = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'config' => ['access_token' => 'token'],
            'status' => 'active',
        ]);

        $this->assertTrue($connector->supports($mailchimp));
        $this->assertFalse($connector->supports($instagram));
    }
}
