<?php

namespace Tests\Feature\App;

use App\Jobs\ProcessObservation;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Observation;
use App\Models\SourceAsset;
use App\Models\User;
use App\Services\SourceAssets\SourceAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SourceAssetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->get('/app/assets')->assertRedirect('/login');
    }

    public function test_index_only_lists_the_active_companys_assets(): void
    {
        [$user, $company] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $this->asset($company, ['title' => 'Our offer']);
        $this->asset($other, ['title' => 'Secret offer']);

        $this->actingAs($user)->get('/app/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Assets/Index')
                ->has('assets', 1)
                ->where('assets.0.title', 'Our offer')
            );
    }

    public function test_customer_can_add_an_asset_and_queue_analysis(): void
    {
        Queue::fake();
        Storage::fake('public');
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)->post('/app/assets', [
            'type' => 'promotion_event',
            'title' => 'Summer launch',
            'description' => 'A limited launch for current customers.',
            'source_url' => 'https://example.com/summer',
            'media' => UploadedFile::fake()->image('launch.jpg'),
            'ends_at' => now()->addWeek()->toDateTimeString(),
        ])->assertRedirect();

        $asset = SourceAsset::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame('processing', $asset->status);
        $this->assertNotNull($asset->observation_id);
        $this->assertStringStartsWith("source-assets/{$company->id}/", (string) $asset->media_path);
        $this->assertSame('image/jpeg', $asset->media_mime_type);
        Storage::disk('public')->assertExists($asset->media_path);
        Queue::assertPushed(ProcessObservation::class, fn (ProcessObservation $job) => $job->observation->id === $asset->observation_id);
    }

    public function test_index_exposes_media_mime_type_for_safe_previews(): void
    {
        Storage::fake('public');
        Queue::fake();
        [$user] = $this->userWithCompany();

        $this->actingAs($user)->post('/app/assets', [
            'type' => 'document_case_study',
            'title' => 'Customer story',
            'media' => UploadedFile::fake()->create('story.pdf', 10, 'application/pdf'),
        ]);

        $this->actingAs($user)->get('/app/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assets.0.media_mime_type', 'application/pdf')
                ->where('assets.0.media_url', fn (string $url): bool => str_contains($url, '/storage/source-assets/'))
            );
    }

    public function test_duplicate_submission_is_idempotent(): void
    {
        Queue::fake();
        [$user] = $this->userWithCompany();
        $payload = [
            'type' => 'product_service',
            'title' => 'Consulting package',
            'description' => 'Strategy and implementation.',
        ];

        $this->actingAs($user)->post('/app/assets', $payload);
        $this->actingAs($user)->post('/app/assets', $payload);

        $this->assertDatabaseCount('source_assets', 1);
        $this->assertDatabaseCount('observations', 1);
    }

    public function test_different_uploaded_media_with_same_fields_create_distinct_assets(): void
    {
        Queue::fake();
        Storage::fake('public');
        [$user] = $this->userWithCompany();
        $payload = [
            'type' => 'photo_video',
            'title' => 'Campaign hero',
            'description' => 'Current campaign creative.',
        ];

        $this->actingAs($user)->post('/app/assets', [
            ...$payload,
            'media' => UploadedFile::fake()->createWithContent('hero.jpg', 'first-image'),
        ]);
        $this->actingAs($user)->post('/app/assets', [
            ...$payload,
            'media' => UploadedFile::fake()->createWithContent('hero.jpg', 'second-image'),
        ]);

        $this->assertDatabaseCount('source_assets', 2);
        $this->assertDatabaseCount('observations', 2);
    }

    public function test_same_uploaded_media_and_fields_remain_idempotent(): void
    {
        Queue::fake();
        Storage::fake('public');
        [$user] = $this->userWithCompany();
        $payload = [
            'type' => 'document_case_study',
            'title' => 'Customer story',
            'description' => 'Proof from a customer.',
        ];

        foreach (range(1, 2) as $_attempt) {
            $this->actingAs($user)->post('/app/assets', [
                ...$payload,
                'media' => UploadedFile::fake()->createWithContent('story.pdf', 'same-document'),
            ]);
        }

        $this->assertDatabaseCount('source_assets', 1);
        $this->assertDatabaseCount('observations', 1);
    }

    public function test_replacing_media_changes_identity_and_queues_fresh_analysis(): void
    {
        Queue::fake();
        Storage::fake('public');
        [$user] = $this->userWithCompany();
        $payload = [
            'type' => 'photo_video',
            'title' => 'Campaign hero',
            'description' => 'Current campaign creative.',
        ];

        $this->actingAs($user)->post('/app/assets', [
            ...$payload,
            'media' => UploadedFile::fake()->createWithContent('hero.jpg', 'first-image'),
        ]);
        $asset = SourceAsset::withoutGlobalScopes()->firstOrFail();
        $originalFingerprint = $asset->content_fingerprint;
        Queue::fake();

        $this->actingAs($user)->put("/app/assets/{$asset->id}", [
            ...$payload,
            'media' => UploadedFile::fake()->createWithContent('hero.jpg', 'replacement-image'),
        ])->assertRedirect();

        $this->assertNotSame($originalFingerprint, $asset->refresh()->content_fingerprint);
        $this->assertDatabaseCount('source_assets', 1);
        $this->assertDatabaseCount('observations', 2);
        Queue::assertPushed(ProcessObservation::class);
    }

    public function test_failed_create_removes_newly_stored_media(): void
    {
        Queue::fake();
        Storage::fake('public');
        [, $company] = $this->userWithCompany();
        $service = $this->app->make(SourceAssetService::class);
        $event = 'eloquent.creating: '.Observation::class;
        Event::listen($event, static fn (): never => throw new \RuntimeException('Forced observation failure.'));

        try {
            $service->create($company, [
                'type' => 'document_case_study',
                'title' => 'Customer proof',
                'media' => UploadedFile::fake()->createWithContent('proof.pdf', 'new-upload'),
            ]);
            $this->fail('Expected source asset persistence to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced observation failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseMissing('source_assets', ['company_id' => $company->id]);
        $this->assertSame([], Storage::disk('public')->allFiles("source-assets/{$company->id}"));
    }

    public function test_failed_update_removes_replacement_and_preserves_original_media(): void
    {
        Queue::fake();
        Storage::fake('public');
        [, $company] = $this->userWithCompany();
        $service = $this->app->make(SourceAssetService::class);
        $asset = $service->create($company, [
            'type' => 'photo_video',
            'title' => 'Original creative',
            'media' => UploadedFile::fake()->createWithContent('original.jpg', 'original-upload'),
        ]);
        $originalPath = $asset->media_path;
        $event = 'eloquent.creating: '.Observation::class;
        Event::listen($event, static fn (): never => throw new \RuntimeException('Forced observation failure.'));

        try {
            $service->update($asset, [
                'type' => 'photo_video',
                'title' => 'Replacement creative',
                'media' => UploadedFile::fake()->createWithContent('replacement.jpg', 'replacement-upload'),
            ]);
            $this->fail('Expected source asset persistence to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced observation failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $asset->refresh();
        $this->assertSame('Original creative', $asset->title);
        $this->assertSame($originalPath, $asset->media_path);
        Storage::disk('public')->assertExists($originalPath);
        $this->assertSame([$originalPath], Storage::disk('public')->allFiles("source-assets/{$company->id}"));
    }

    public function test_customer_cannot_mutate_another_companys_asset(): void
    {
        Queue::fake();
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $asset = $this->asset($other);

        $this->actingAs($user)->delete("/app/assets/{$asset->id}")->assertNotFound();
        $this->actingAs($user)->post("/app/assets/{$asset->id}/retry")->assertNotFound();
        $this->assertNotSoftDeleted($asset);
    }

    public function test_customer_can_archive_own_asset(): void
    {
        [$user, $company] = $this->userWithCompany();
        $asset = $this->asset($company);

        $this->actingAs($user)->delete("/app/assets/{$asset->id}")->assertRedirect();

        $this->assertSoftDeleted($asset);
    }

    public function test_customer_can_update_own_asset_and_queue_fresh_analysis(): void
    {
        Queue::fake();
        [$user, $company] = $this->userWithCompany();
        $asset = $this->asset($company);

        $this->actingAs($user)->put("/app/assets/{$asset->id}", [
            'type' => 'document_case_study',
            'title' => 'Updated customer story',
            'description' => 'New proof and results.',
        ])->assertRedirect();

        $asset->refresh();
        $this->assertSame('Updated customer story', $asset->title);
        $this->assertSame('processing', $asset->status);
        $this->assertNotNull($asset->observation_id);
        Queue::assertPushed(ProcessObservation::class);
    }

    public function test_update_to_an_identical_existing_asset_returns_validation_error(): void
    {
        Queue::fake();
        [$user, $company] = $this->userWithCompany();
        $service = $this->app->make(SourceAssetService::class);
        $existing = $service->create($company, ['type' => 'product_service', 'title' => 'Existing asset']);
        $candidate = $service->create($company, ['type' => 'product_service', 'title' => 'Candidate asset']);

        $this->actingAs($user)->put("/app/assets/{$candidate->id}", [
            'type' => $existing->type,
            'title' => $existing->title,
        ])->assertSessionHasErrors('title');

        $this->assertSame('Candidate asset', $candidate->refresh()->title);
    }

    public function test_archived_asset_can_be_added_again(): void
    {
        Queue::fake();
        [$user, $company] = $this->userWithCompany();
        $payload = ['type' => 'product_service', 'title' => 'Reusable offer'];

        $this->actingAs($user)->post('/app/assets', $payload);
        $asset = SourceAsset::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
        $this->actingAs($user)->delete("/app/assets/{$asset->id}");
        $this->actingAs($user)->post('/app/assets', $payload);

        $this->assertSame(2, SourceAsset::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(1, SourceAsset::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('deleted_at')->count());
    }

    /** @return array{User, Company} */
    private function userWithCompany(): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        return [$user, $company];
    }

    /** @param array<string, mixed> $overrides */
    private function asset(Company $company, array $overrides = []): SourceAsset
    {
        return SourceAsset::withoutGlobalScopes()->create(array_merge([
            'company_id' => $company->id,
            'type' => 'product_service',
            'title' => 'New service',
            'status' => 'ready',
            'content_fingerprint' => hash('sha256', $company->id.($overrides['title'] ?? 'New service')),
        ], $overrides));
    }
}
