<?php

namespace Tests\Feature\App;

use App\AI\Contracts\AiProvider;
use App\AI\Images\Contracts\ImageProvider;
use App\AI\Images\Exceptions\ImageGenerationException;
use App\AI\Images\Testing\FakeImageProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\RecommendationApproved;
use App\Models\Campaign;
use App\Models\CampaignBrief;
use App\Models\CampaignImageGeneration;
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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomCampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private FakeImageProvider $fakeImages;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->fake = new FakeAiProvider;
        $this->app->instance(AiProvider::class, $this->fake);

        $this->fakeImages = new FakeImageProvider;
        $this->app->instance(ImageProvider::class, $this->fakeImages);
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

    public function test_store_composes_a_prompt_only_campaign_without_any_assets(): void
    {
        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);
        $channel = $this->channel($company);

        $this->fake
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        $response = $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [],
            'channel_ids' => [$channel->id],
        ]);

        $brief = CampaignBrief::withoutGlobalScopes()->with('sourceAssets')->firstOrFail();
        $recommendation = Recommendation::withoutGlobalScopes()->firstOrFail();

        $response->assertRedirect(route('app.recommendations.show', $recommendation));
        $this->assertCount(0, $brief->sourceAssets);
        $this->assertSame('Fall customer appreciation', $brief->title);
        $this->assertStringContainsString(
            'No source assets supplied',
            Opportunity::withoutGlobalScopes()->firstOrFail()->description,
        );
    }

    public function test_store_derives_a_title_from_the_objective_when_title_is_omitted(): void
    {
        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);
        $channel = $this->channel($company);

        $this->fake
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        $payload = $this->validPayload();
        unset($payload['title']);

        $this->actingAs($user)->post('/app/campaigns', [
            ...$payload,
            'objective' => 'Invite current customers to book the strategy intensive this fall. Emphasise the year-end availability.',
            'source_asset_ids' => [],
            'channel_ids' => [$channel->id],
        ])->assertRedirect();

        $brief = CampaignBrief::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Invite current customers to book the strategy intensive this fall', $brief->title);
    }

    public function test_store_rejects_a_submission_without_an_objective_prompt(): void
    {
        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);
        $asset = $this->asset($company);
        $channel = $this->channel($company);

        $payload = $this->validPayload();
        unset($payload['objective']);

        $this->actingAs($user)->post('/app/campaigns', [
            ...$payload,
            'source_asset_ids' => [$asset->id],
            'channel_ids' => [$channel->id],
        ])->assertSessionHasErrors('objective');

        $this->assertDatabaseCount('campaign_briefs', 0);
    }

    public function test_store_fails_cleanly_without_an_asset_or_business_brain(): void
    {
        [$user, $company] = $this->userWithCompany();
        $channel = $this->channel($company);

        $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [],
            'channel_ids' => [$channel->id],
        ])->assertSessionHasErrors('objective');

        $this->assertDatabaseCount('campaign_briefs', 0);
        $this->assertDatabaseCount('decisions', 0);
        $this->fake->assertNothingSent();
    }

    public function test_prompt_only_campaign_generates_and_attaches_imagery(): void
    {
        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);
        $channel = $this->channel($company);

        $this->fake
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [],
            'channel_ids' => [$channel->id],
        ])->assertRedirect();

        $this->fakeImages->assertGenerated();

        $generation = CampaignImageGeneration::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('ready', $generation->status);
        $this->assertNotNull($generation->media_path);
        $this->assertNotEmpty($generation->prompt);
        Storage::disk('public')->assertExists($generation->media_path);

        // Grounded prompt, not a raw passthrough of the customer objective.
        $this->assertStringContainsString('Clear Move', $generation->prompt);
        $this->assertStringNotContainsString($this->validPayload()['objective'], $generation->prompt);

        $recommendation = Recommendation::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($user)
            ->get(route('app.recommendations.show', $recommendation))
            ->assertInertia(fn ($page) => $page
                ->has('generated_imagery', 1)
                ->where('generated_imagery.0.status', 'ready')
            );
    }

    public function test_imagery_failure_does_not_block_the_campaign(): void
    {
        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);
        $channel = $this->channel($company);

        $this->fakeImages->queueException(ImageGenerationException::failed('fake', 'model unavailable'));
        $this->fake
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        $response = $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [],
            'channel_ids' => [$channel->id],
        ]);

        $recommendation = Recommendation::withoutGlobalScopes()->firstOrFail();
        $response->assertRedirect(route('app.recommendations.show', $recommendation));

        $this->assertSame('pending', $recommendation->status);
        $this->assertDatabaseHas('content_assets', ['status' => 'draft']);

        $generation = CampaignImageGeneration::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $generation->status);
        $this->assertNotNull($generation->error);
    }

    public function test_generation_cap_breach_surfaces_a_message_without_a_hard_error(): void
    {
        config()->set('ai.image.company_cap.limit', 2);

        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);
        $channel = $this->channel($company);

        foreach (range(1, 2) as $i) {
            CampaignImageGeneration::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'status' => 'ready',
                'media_path' => "campaign-images/{$company->id}/existing-{$i}.png",
            ]);
        }

        $this->fake
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        $recommendation = null;
        $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [],
            'channel_ids' => [$channel->id],
        ])->assertRedirect();

        $this->fakeImages->assertNothingGenerated();

        $generation = CampaignImageGeneration::withoutGlobalScopes()
            ->whereNotNull('campaign_brief_id')
            ->firstOrFail();
        $this->assertSame('failed', $generation->status);
        $this->assertStringContainsString('limit of 2 campaign images', (string) $generation->error);
    }

    public function test_supplying_own_assets_skips_generation_unless_opted_in(): void
    {
        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);
        $asset = $this->asset($company);
        $channel = $this->channel($company);

        $this->fake
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [$asset->id],
            'channel_ids' => [$channel->id],
        ])->assertRedirect();

        $this->fakeImages->assertNothingGenerated();
        $this->assertDatabaseCount('campaign_image_generations', 0);
    }

    public function test_supplying_own_assets_with_opt_in_generates_imagery(): void
    {
        [$user, $company] = $this->userWithCompany();
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);
        $asset = $this->asset($company);
        $channel = $this->channel($company);

        $this->fake
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        $this->actingAs($user)->post('/app/campaigns', [
            ...$this->validPayload(),
            'source_asset_ids' => [$asset->id],
            'channel_ids' => [$channel->id],
            'generate_imagery' => true,
        ])->assertRedirect();

        $this->fakeImages->assertGenerated();
        $this->assertSame(
            'ready',
            CampaignImageGeneration::withoutGlobalScopes()->firstOrFail()->status,
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
