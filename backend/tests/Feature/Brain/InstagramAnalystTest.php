<?php

namespace Tests\Feature\Brain;

use App\Models\Company;
use App\Models\InstagramAccount;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\Exceptions\FactExtractionFailedException;
use App\Services\Analyst\InstagramAnalyst;
use App\Services\Brain\Data\FactData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstagramAnalystTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Integration $integration;

    private InstagramAnalyst $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions',
            'slug' => 'cbb-auctions',
        ]);

        $this->integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'config' => ['access_token' => 'token-123'],
            'status' => 'active',
        ]);

        $this->analyst = $this->app->make(InstagramAnalyst::class);
    }

    private function makeObservation(array $payload): Observation
    {
        return Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'integration_id' => $this->integration->id,
            'source_type' => 'social',
            'source_identifier' => (string) ($payload['username'] ?? 'unknown'),
            'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }

    public function test_supports_only_social_observations(): void
    {
        $social = $this->makeObservation(['username' => 'cbb_auctions']);
        $crawl = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://example.com',
            'raw_payload' => '{}',
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        $this->assertTrue($this->analyst->supports($social));
        $this->assertFalse($this->analyst->supports($crawl));
    }

    public function test_extracts_facts_from_a_full_profile_payload(): void
    {
        $observation = $this->makeObservation([
            'account_id' => '17841400000000',
            'username' => 'cbb_auctions',
            'display_name' => 'CBB Auctions',
            'bio' => 'Comic book auctions every week.',
            'website' => 'https://cbbauctions.com',
            'follower_count' => 4210,
            'following_count' => 180,
            'fetched_at' => now()->toIso8601String(),
        ]);

        $facts = $this->analyst->analyze($observation);

        $this->assertContainsOnlyInstancesOf(FactData::class, $facts);
        $this->assertCount(6, $facts);

        $byKey = $facts->keyBy('key');
        $this->assertSame('cbb_auctions', $byKey->get('instagram.username')->value);
        $this->assertSame('CBB Auctions', $byKey->get('instagram.display_name')->value);
        $this->assertSame('Comic book auctions every week.', $byKey->get('instagram.bio')->value);
        $this->assertSame('https://cbbauctions.com', $byKey->get('instagram.website')->value);
        $this->assertSame(4210, $byKey->get('instagram.follower_count')->value);
        $this->assertSame(180, $byKey->get('instagram.following_count')->value);
    }

    public function test_omits_facts_for_null_optional_fields(): void
    {
        $observation = $this->makeObservation([
            'account_id' => '17841400000000',
            'username' => 'cbb_auctions',
            'display_name' => null,
            'bio' => null,
            'website' => null,
            'follower_count' => null,
            'following_count' => null,
        ]);

        $facts = $this->analyst->analyze($observation);

        $this->assertCount(1, $facts);
        $this->assertSame('instagram.username', $facts->first()->key);
    }

    public function test_throws_when_username_is_missing(): void
    {
        $observation = $this->makeObservation(['account_id' => '17841400000000']);

        $this->expectException(FactExtractionFailedException::class);
        $this->expectExceptionMessage('missing the required username field');

        $this->analyst->analyze($observation);
    }

    public function test_upserts_the_instagram_account_snapshot(): void
    {
        $observation = $this->makeObservation([
            'account_id' => '17841400000000',
            'username' => 'cbb_auctions',
            'display_name' => 'CBB Auctions',
            'profile_picture_url' => 'https://example.com/pic.jpg',
            'bio' => 'Comic book auctions every week.',
            'website' => 'https://cbbauctions.com',
            'follower_count' => 4210,
            'following_count' => 180,
        ]);

        $this->analyst->analyze($observation);

        $account = InstagramAccount::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($account);
        $this->assertSame('17841400000000', $account->account_id);
        $this->assertSame('cbb_auctions', $account->username);
        $this->assertSame('CBB Auctions', $account->display_name);
        $this->assertSame(4210, $account->follower_count);
        $this->assertSame(180, $account->following_count);
        $this->assertNotNull($account->last_synced_at);
    }

    public function test_re_syncing_updates_the_existing_account_row_not_a_new_one(): void
    {
        $this->analyst->analyze($this->makeObservation([
            'account_id' => '17841400000000',
            'username' => 'cbb_auctions',
            'follower_count' => 4000,
        ]));

        $this->analyst->analyze($this->makeObservation([
            'account_id' => '17841400000000',
            'username' => 'cbb_auctions',
            'follower_count' => 4300,
        ]));

        $this->assertSame(1, InstagramAccount::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        $account = InstagramAccount::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
        $this->assertSame(4300, $account->follower_count);
    }
}
