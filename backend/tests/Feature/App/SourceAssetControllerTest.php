<?php

namespace Tests\Feature\App;

use App\Jobs\ProcessObservation;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\SourceAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        Storage::disk('public')->assertExists($asset->media_path);
        Queue::assertPushed(ProcessObservation::class, fn (ProcessObservation $job) => $job->observation->id === $asset->observation_id);
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
