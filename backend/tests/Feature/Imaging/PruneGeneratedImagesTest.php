<?php

namespace Tests\Feature\Imaging;

use App\Jobs\PruneGeneratedImages;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneGeneratedImagesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Campaign $campaign;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('imaging.disk', 'public');
        config()->set('imaging.retention_days', 30);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB', 'slug' => 'cbb', 'industry' => 'auction',
        ]);
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'instagram', 'name' => 'IG', 'is_active' => true,
        ]);
        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'subject_type' => 'company', 'type' => 'featured_item',
            'title' => 'x', 'description' => 'y', 'relevance_score' => 80, 'timing_score' => 80,
            'confidence_score' => 80, 'urgency_score' => 80, 'composite_score' => 80,
            'status' => 'selected', 'detected_at' => now(),
        ]);
        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'opportunity_id' => $opportunity->id, 'campaign_type' => 'featured_item',
            'channel_ids' => [$this->channel->id], 'rationale' => ['why_now' => 'now'],
            'expected_impact' => ['summary' => 's'], 'confidence_score' => 70, 'status' => 'pending', 'decided_at' => now(),
        ]);
        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'decision_id' => $decision->id, 'campaign_type' => 'featured_item',
            'title' => 'C', 'blueprint' => ['x' => 1], 'blueprint_version' => '1.0', 'prompt_version' => '1.0',
            'expected_asset_count' => 1, 'generated_asset_count' => 0, 'status' => 'draft',
        ]);
    }

    private function putImage(string $name, int $ageInDays): string
    {
        $path = "generated-content/{$this->company->id}/{$this->campaign->id}/{$this->channel->id}/{$name}.png";
        Storage::disk('public')->put($path, 'png-bytes');
        touch(Storage::disk('public')->path($path), now()->subDays($ageInDays)->getTimestamp());

        return $path;
    }

    private function makeAsset(string $path, string $status = 'draft'): ContentAsset
    {
        return ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'social_post',
            'body' => 'copy',
            'media' => [['url' => "/storage/{$path}", 'source' => 'ai_generated']],
            'status' => $status,
        ]);
    }

    public function test_keeps_a_file_a_live_asset_still_references(): void
    {
        $path = $this->putImage('kept', ageInDays: 90);
        $this->makeAsset($path);

        (new PruneGeneratedImages())->handle();

        Storage::disk('public')->assertExists($path);
    }

    public function test_deletes_an_old_orphaned_file(): void
    {
        $path = $this->putImage('orphan', ageInDays: 90);

        (new PruneGeneratedImages())->handle();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_keeps_a_recent_orphan_within_the_grace_period(): void
    {
        $path = $this->putImage('fresh-orphan', ageInDays: 3);

        (new PruneGeneratedImages())->handle();

        Storage::disk('public')->assertExists($path);
    }

    public function test_deletes_a_file_whose_only_asset_was_archived(): void
    {
        $path = $this->putImage('archived', ageInDays: 90);
        $this->makeAsset($path, status: 'archived');

        (new PruneGeneratedImages())->handle();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_ignores_files_outside_the_generated_content_prefix(): void
    {
        Storage::disk('public')->put('other/keep-me.png', 'x');
        touch(Storage::disk('public')->path('other/keep-me.png'), now()->subDays(400)->getTimestamp());

        (new PruneGeneratedImages())->handle();

        Storage::disk('public')->assertExists('other/keep-me.png');
    }
}
