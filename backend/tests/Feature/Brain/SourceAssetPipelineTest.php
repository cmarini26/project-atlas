<?php

namespace Tests\Feature\Brain;

use App\Events\ObservationProcessed;
use App\Events\OpportunityDetected;
use App\Listeners\CreateSourceAssetOpportunity;
use App\Models\Company;
use App\Models\Observation;
use App\Models\Opportunity;
use App\Models\SourceAsset;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Analyst\SourceAssetAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SourceAssetPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_resolves_manual_source_assets_to_dedicated_analyst(): void
    {
        $observation = new Observation([
            'source_type' => 'manual',
            'source_identifier' => 'source_asset:01K00000000000000000000000',
        ]);

        $this->assertInstanceOf(
            SourceAssetAnalyst::class,
            $this->app->make(AnalystRegistry::class)->resolve($observation),
        );
    }

    public function test_analyst_extracts_provenance_preserving_facts(): void
    {
        $id = '01K00000000000000000000000';
        $observation = new Observation([
            'source_type' => 'manual',
            'source_identifier' => "source_asset:{$id}",
            'raw_payload' => json_encode([
                'source_asset_id' => $id,
                'type' => 'promotion_event',
                'title' => 'Summer launch',
                'description' => 'Limited time.',
            ], JSON_THROW_ON_ERROR),
        ]);

        $facts = (new SourceAssetAnalyst())->analyze($observation);

        $this->assertSame([
            "source_asset.{$id}.title",
            "source_asset.{$id}.type",
            "source_asset.{$id}.details",
        ], $facts->pluck('key')->all());
        $this->assertSame(100, $facts->first()->confidence);
    }

    public function test_processed_asset_creates_one_linked_opportunity_and_dispatches_pipeline(): void
    {
        Event::fake([OpportunityDetected::class]);
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        $asset = SourceAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'promotion_event',
            'title' => 'Summer launch',
            'description' => 'Limited time.',
            'status' => 'processing',
            'content_fingerprint' => hash('sha256', 'summer'),
            'ends_at' => now()->addWeek(),
        ]);
        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'source_type' => 'manual',
            'source_identifier' => "source_asset:{$asset->id}",
            'raw_payload' => '{}',
            'status' => 'processed',
            'observed_at' => now(),
            'processed_at' => now(),
        ]);

        $listener = new CreateSourceAssetOpportunity();
        $listener->handle(new ObservationProcessed($observation));
        $listener->handle(new ObservationProcessed($observation));

        $asset->refresh();
        $this->assertSame('ready', $asset->status);
        $this->assertDatabaseCount('opportunities', 1);
        $opportunity = Opportunity::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('source_asset', $opportunity->subject_type);
        $this->assertSame($asset->id, $opportunity->subject_id);
        $this->assertSame($asset->id, $opportunity->subject->id);
        Event::assertDispatchedTimes(OpportunityDetected::class, 1);
    }
}
