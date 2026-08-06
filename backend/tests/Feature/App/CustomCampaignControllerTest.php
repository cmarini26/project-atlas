<?php

namespace Tests\Feature\App;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\RecommendationApproved;
use App\Models\Campaign;
use App\Models\CampaignBrief;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\SourceAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CustomCampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);
    }

    public function test_create_requires_authentication(): void
    {
        $this->get('/app/campaigns/create')->assertRedirect('/login');
    }

    public function test_create_only_lists_ready_assets_and_active_channels_for_the_company(): void
    {
        [$user, $company] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        $ready = $this->asset($company, ['title' => 'Ready offer']);
        $this->asset($company, ['title' => 'Still processing', 'status' => 'processing']);
        $this->asset($other, ['title' => 'Private offer']);

        $active = $this->channel($company, ['name' => 'Customer email']);
        $this->channel($company, ['name' => 'Inactive email', 'is_active' => false]);
        $this->channel($other, ['name' => 'Other email']);

        $this->actingAs($user)
            ->get("/app/campaigns/create?asset_id={$ready->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Campaigns/Create')
                ->has('assets', 1)
                ->where('assets.0.title', 'Ready offer')
                ->has('channels', 1)
                ->where('channels.0.id', $active->id)
                ->where('initial_asset_ids.0', $ready->id)
            );
    }

    public function test_store_rejects_assets_and_channels_from_another_company(): void
    {
        [$user, $company] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);

        $response = $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [$this->asset($other)->id],
            'channel_ids' => [$this->channel($other)->id],
        ]);

        $response->assertSessionHasErrors('source_asset_ids');
        $this->assertDatabaseCount('campaign_briefs', 0);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_customer_can_prepare_a_custom_campaign_from_multiple_assets_without_publishing(): void
    {
        Event::fake([RecommendationApproved::class]);
        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);

        $offer = $this->asset($company, [
            'title' => 'Strategy intensive',
            'description' => 'A focused strategy service.',
            'media_path' => 'source-assets/strategy.jpg',
            'media_mime_type' => 'image/jpeg',
        ]);
        $proof = $this->asset($company, [
            'type' => 'document_case_study',
            'title' => 'Customer growth story',
            'description' => 'A customer case study.',
        ]);
        $channel = $this->channel($company);

        $this->fake
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        $response = $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [$offer->id, $proof->id],
            'channel_ids' => [$channel->id],
        ]);

        $brief = CampaignBrief::withoutGlobalScopes()->with('sourceAssets')->firstOrFail();
        $decision = Decision::withoutGlobalScopes()->firstOrFail();
        $campaign = Campaign::withoutGlobalScopes()->firstOrFail();
        $content = ContentAsset::withoutGlobalScopes()->firstOrFail();
        $recommendation = Recommendation::withoutGlobalScopes()->firstOrFail();

        $response->assertRedirect(route('app.recommendations.show', $recommendation));
        $this->assertSame('Fall customer appreciation', $brief->title);
        $this->assertEqualsCanonicalizing([$offer->id, $proof->id], $brief->sourceAssets->pluck('id')->all());
        $this->assertSame([$channel->id], $decision->channel_ids);
        $this->assertSame($brief->id, $campaign->campaign_brief_id);
        $this->assertSame($brief->title, $campaign->title);
        $this->assertSame($brief->objective, $campaign->strategy);
        $this->assertSame($brief->audience, $campaign->target_audience);
        $this->assertSame('draft', $content->status);
        $this->assertSame('pending', $recommendation->status);
        $this->assertStringContainsString('source-assets/strategy.jpg', $content->media[0]['url']);
        $this->assertDatabaseCount('executions', 0);
        Event::assertNotDispatched(RecommendationApproved::class);

        $this->actingAs($user)
            ->get(route('app.recommendations.show', $recommendation))
            ->assertInertia(fn ($page) => $page
                ->where('campaign_brief.objective', $brief->objective)
                ->where('campaign_brief.audience', $brief->audience)
                ->has('source_assets', 2)
                ->where('source_assets.0.id', $offer->id)
                ->where('source_assets.1.id', $proof->id)
            );
    }

    public function test_custom_campaign_context_names_the_verified_wordpress_target(): void
    {
        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);
        $asset = $this->asset($company);
        $channel = $this->channel($company, [
            'type' => 'blog',
            'name' => 'Northwind WordPress',
            'config' => ['site_url' => 'https://northwind.example/'],
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'blog',
            'provider_type' => 'wordpress',
            'credentials' => json_encode(['username' => 'atlas', 'app_password' => 'secret']),
            'status' => 'active',
        ]);
        $this->fake
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('blog-content');

        $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [$asset->id],
            'channel_ids' => [$channel->id],
        ])->assertRedirect();

        $description = Opportunity::withoutGlobalScopes()->firstOrFail()->description;
        $this->assertStringContainsString(
            'Verified publishing targets: Northwind WordPress: https://northwind.example',
            $description,
        );
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'title' => 'Fall customer appreciation',
            'goal' => 'conversion',
            'objective' => 'Invite current customers to book the strategy intensive this fall.',
            'audience' => 'Current customers who have not booked this year.',
            'guidance' => 'Use a warm tone and include a clear booking call to action.',
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addWeek()->toDateTimeString(),
            'source_asset_ids' => [],
            'channel_ids' => [],
        ];
    }

    /** @return array{User, Company} */
    private function userWithCompany(): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Clear Move', 'slug' => 'clear-move']);
        CompanyMembership::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user, $company];
    }

    /** @param array<string, mixed> $overrides */
    private function asset(Company $company, array $overrides = []): SourceAsset
    {
        return SourceAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'product_service',
            'title' => 'Core offer',
            'description' => 'The company’s primary service.',
            'status' => 'ready',
            'processed_at' => now(),
            'content_fingerprint' => hash('sha256', $company->id.'|'.($overrides['title'] ?? 'Core offer').'|'.uniqid()),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function channel(Company $company, array $overrides = []): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
            ...$overrides,
        ]);
    }
}
