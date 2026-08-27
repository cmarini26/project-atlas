<?php

namespace Tests\Feature\Jobs;

use App\AI\Images\Contracts\ImageProvider;
use App\AI\Images\Exceptions\ImageGenerationException;
use App\AI\Images\ImageGenerationCap;
use App\AI\Images\ImageStorage;
use App\AI\Images\Testing\FakeImageProvider;
use App\Jobs\GenerateCampaignImagery;
use App\Models\CampaignBrief;
use App\Models\CampaignImageGeneration;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Services\Brain\BusinessBrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateCampaignImageryTest extends TestCase
{
    use RefreshDatabase;

    private FakeImageProvider $images;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->images = new FakeImageProvider;
        $this->app->instance(ImageProvider::class, $this->images);
    }

    private function pendingGeneration(): CampaignImageGeneration
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $company->id, 'status' => 'active', 'health_score' => 70]);
        $brief = CampaignBrief::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'title' => 'Spring push',
            'goal' => 'conversion',
            'objective' => 'Fill spring appointment slots with returning customers.',
            'campaign_type' => 'featured_item',
            'channel_ids' => [],
        ]);

        return CampaignImageGeneration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_brief_id' => $brief->id,
            'status' => 'pending',
        ]);
    }

    public function test_it_stores_the_image_and_records_cost(): void
    {
        $this->images->costPerImageUsd = 0.011;
        $generation = $this->pendingGeneration();

        (new GenerateCampaignImagery($generation->id))->handle(
            $this->images,
            app(ImageStorage::class),
            app(ImageGenerationCap::class),
            app(BusinessBrainService::class),
        );

        $generation->refresh();
        $this->assertSame('ready', $generation->status);
        $this->assertSame('0.0110', (string) $generation->cost_usd);
        Storage::disk('public')->assertExists($generation->media_path);
    }

    public function test_a_non_pending_row_is_left_untouched(): void
    {
        $generation = $this->pendingGeneration();
        $generation->update(['status' => 'ready']);

        (new GenerateCampaignImagery($generation->id))->handle(
            $this->images,
            app(ImageStorage::class),
            app(ImageGenerationCap::class),
            app(BusinessBrainService::class),
        );

        $this->images->assertNothingGenerated();
    }

    public function test_cap_breach_marks_the_row_failed_without_calling_the_provider(): void
    {
        config()->set('ai.image.company_cap.limit', 1);
        $generation = $this->pendingGeneration();
        CampaignImageGeneration::withoutGlobalScopes()->create([
            'company_id' => $generation->company_id,
            'status' => 'ready',
        ]);

        (new GenerateCampaignImagery($generation->id))->handle(
            $this->images,
            app(ImageStorage::class),
            app(ImageGenerationCap::class),
            app(BusinessBrainService::class),
        );

        $this->images->assertNothingGenerated();
        $this->assertSame('failed', $generation->refresh()->status);
        $this->assertStringContainsString('limit of 1', (string) $generation->error);
    }

    public function test_the_failed_hook_marks_a_still_pending_row_failed(): void
    {
        $generation = $this->pendingGeneration();

        (new GenerateCampaignImagery($generation->id))->failed(
            ImageGenerationException::transient('fake', 'gave up'),
        );

        $this->assertSame('failed', $generation->refresh()->status);
        $this->assertNotNull($generation->error);
    }
}
