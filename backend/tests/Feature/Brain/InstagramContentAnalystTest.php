<?php

namespace Tests\Feature\Brain;

use App\Models\Company;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\Exceptions\FactExtractionFailedException;
use App\Services\Analyst\InstagramAnalyst;
use App\Services\Brain\Data\FactData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Milestone 12 Phase 2 — Instagram Content Intelligence. Covers the
 * deterministic facts InstagramAnalyst derives from a 'social_content'
 * Observation (recent posts), distinct from the Phase 1 profile-snapshot
 * facts already covered by InstagramAnalystTest.
 */
class InstagramContentAnalystTest extends TestCase
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

    /** @param array<int, array<string, mixed>> $posts */
    private function makeObservation(array $posts): Observation
    {
        return Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'integration_id' => $this->integration->id,
            'source_type' => 'social_content',
            'source_identifier' => 'cbb_auctions-recent-media',
            'raw_payload' => json_encode(['posts' => $posts, 'media_limit' => 20], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }

    private function makePost(array $overrides = []): array
    {
        return array_merge([
            'id' => '1',
            'caption' => 'A great post.',
            'timestamp' => '2026-07-01T12:00:00+00:00',
            'media_type' => 'IMAGE',
            'permalink' => 'https://instagram.com/p/1',
            'like_count' => null,
            'comments_count' => null,
            'hashtags' => [],
            'mentions' => [],
        ], $overrides);
    }

    public function test_supports_social_content_observations(): void
    {
        $observation = $this->makeObservation([$this->makePost()]);

        $this->assertTrue($this->analyst->supports($observation));
    }

    public function test_throws_when_posts_key_is_missing(): void
    {
        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'integration_id' => $this->integration->id,
            'source_type' => 'social_content',
            'source_identifier' => 'cbb_auctions-recent-media',
            'raw_payload' => json_encode(['media_limit' => 20], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        $this->expectException(FactExtractionFailedException::class);
        $this->expectExceptionMessage('missing the required posts array');

        $this->analyst->analyze($observation);
    }

    public function test_returns_no_facts_for_an_empty_post_list(): void
    {
        $observation = $this->makeObservation([]);

        $facts = $this->analyst->analyze($observation);

        $this->assertCount(0, $facts);
    }

    public function test_computes_media_mix(): void
    {
        $observation = $this->makeObservation([
            $this->makePost(['media_type' => 'IMAGE']),
            $this->makePost(['media_type' => 'IMAGE']),
            $this->makePost(['media_type' => 'VIDEO']),
        ]);

        $facts = $this->analyst->analyze($observation);
        $byKey = $facts->keyBy('key');

        $this->assertSame(['IMAGE' => 2, 'VIDEO' => 1], $byKey->get('instagram.media_mix')->value);
    }

    public function test_computes_hashtag_usage(): void
    {
        $observation = $this->makeObservation([
            $this->makePost(['hashtags' => ['comics', 'auction']]),
            $this->makePost(['hashtags' => ['comics']]),
        ]);

        $facts = $this->analyst->analyze($observation);
        $byKey = $facts->keyBy('key');

        $usage = $byKey->get('instagram.hashtag_usage')->value;
        $this->assertSame(1.5, $usage['avg_per_post']);
        $this->assertSame(['tag' => 'comics', 'count' => 2], $usage['top'][0]);
    }

    public function test_computes_cta_usage_percentage(): void
    {
        $observation = $this->makeObservation([
            $this->makePost(['caption' => 'Shop now before it sells out!']),
            $this->makePost(['caption' => 'Just a regular update.']),
        ]);

        $facts = $this->analyst->analyze($observation);
        $byKey = $facts->keyBy('key');

        $this->assertSame(50.0, $byKey->get('instagram.cta_usage')->value);
    }

    public function test_computes_content_distribution_by_day_of_week(): void
    {
        $day = date('l', strtotime('2026-07-01'));

        $observation = $this->makeObservation([
            $this->makePost(['timestamp' => '2026-07-01T09:00:00+00:00']),
            $this->makePost(['timestamp' => '2026-07-01T18:00:00+00:00']),
        ]);

        $facts = $this->analyst->analyze($observation);
        $byKey = $facts->keyBy('key');

        $distribution = $byKey->get('instagram.content_distribution')->value;
        $this->assertSame(2, $distribution[$day]);
        $this->assertSame(7, count($distribution));
    }

    public function test_computes_posting_cadence_across_a_known_span(): void
    {
        $observation = $this->makeObservation([
            $this->makePost(['timestamp' => '2026-07-01T00:00:00+00:00']),
            $this->makePost(['timestamp' => '2026-07-08T00:00:00+00:00']),
            $this->makePost(['timestamp' => '2026-07-15T00:00:00+00:00']),
        ]);

        $facts = $this->analyst->analyze($observation);
        $byKey = $facts->keyBy('key');

        // 3 posts across a 14-day span => (3-1)/14*7 = 1.0 posts/week.
        $this->assertSame(1.0, $byKey->get('instagram.posting_cadence')->value);
    }

    public function test_omits_posting_cadence_with_fewer_than_two_posts(): void
    {
        $observation = $this->makeObservation([$this->makePost()]);

        $facts = $this->analyst->analyze($observation);

        $this->assertFalse($facts->keyBy('key')->has('instagram.posting_cadence'));
    }

    public function test_computes_an_increasing_engagement_trend(): void
    {
        $observation = $this->makeObservation([
            $this->makePost(['timestamp' => '2026-07-01T00:00:00+00:00', 'like_count' => 10, 'comments_count' => 0]),
            $this->makePost(['timestamp' => '2026-07-02T00:00:00+00:00', 'like_count' => 10, 'comments_count' => 0]),
            $this->makePost(['timestamp' => '2026-07-03T00:00:00+00:00', 'like_count' => 100, 'comments_count' => 20]),
            $this->makePost(['timestamp' => '2026-07-04T00:00:00+00:00', 'like_count' => 120, 'comments_count' => 30]),
        ]);

        $facts = $this->analyst->analyze($observation);
        $byKey = $facts->keyBy('key');

        $trend = $byKey->get('instagram.engagement_trend')->value;
        $this->assertSame('increasing', $trend['trend']);
        $this->assertGreaterThan(0, $trend['avg_likes']);
    }

    public function test_omits_engagement_trend_when_no_posts_have_engagement_data(): void
    {
        $observation = $this->makeObservation([
            $this->makePost(['like_count' => null, 'comments_count' => null]),
        ]);

        $facts = $this->analyst->analyze($observation);

        $this->assertFalse($facts->keyBy('key')->has('instagram.engagement_trend'));
    }

    public function test_returns_only_fact_data_instances(): void
    {
        $observation = $this->makeObservation([
            $this->makePost(['like_count' => 5, 'comments_count' => 1]),
            $this->makePost(['timestamp' => '2026-07-02T00:00:00+00:00']),
        ]);

        $facts = $this->analyst->analyze($observation);

        $this->assertContainsOnlyInstancesOf(FactData::class, $facts);
    }
}
