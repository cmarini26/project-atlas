<?php

namespace Tests\Feature\Brain;

use App\Domain\BusinessBrain\MarketingPresenceSummary;
use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\Brain\MarketingPresenceSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingPresenceSynthesizerTest extends TestCase
{
    use RefreshDatabase;

    private MarketingPresenceSynthesizer $synthesizer;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->synthesizer = new MarketingPresenceSynthesizer();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_returns_a_marketing_presence_summary(): void
    {
        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertInstanceOf(MarketingPresenceSummary::class, $summary);
    }

    public function test_empty_when_no_channels_declared(): void
    {
        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertSame([], $summary->primaryChannels);
        $this->assertSame([], $summary->secondaryChannels);
        $this->assertSame([], $summary->inactiveChannels);
        $this->assertSame([], $summary->primaryObjectives);
        $this->assertSame('No marketing channels have been declared yet.', $summary->summary);
    }

    public function test_buckets_primary_importance_active_channels(): void
    {
        $this->declare(['display_name' => 'Instagram', 'importance' => 'primary', 'status' => 'active']);

        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertSame(['Instagram'], $summary->primaryChannels);
        $this->assertSame([], $summary->secondaryChannels);
        $this->assertSame([], $summary->inactiveChannels);
    }

    public function test_buckets_secondary_and_experimental_importance_together(): void
    {
        $this->declare(['display_name' => 'Facebook', 'importance' => 'secondary', 'status' => 'active']);
        $this->declare(['display_name' => 'TikTok', 'importance' => 'experimental', 'status' => 'active', 'type' => 'tiktok']);

        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertSame(['Facebook', 'TikTok'], $summary->secondaryChannels);
        $this->assertSame([], $summary->primaryChannels);
    }

    public function test_buckets_by_status_inactive_regardless_of_importance(): void
    {
        // A once-primary channel the business stopped using is "inactive," not "primary."
        $this->declare(['display_name' => 'Old Instagram', 'importance' => 'primary', 'status' => 'inactive']);

        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertSame(['Old Instagram'], $summary->inactiveChannels);
        $this->assertSame([], $summary->primaryChannels);
    }

    public function test_planned_and_occasional_are_treated_as_active_for_bucketing(): void
    {
        $this->declare(['display_name' => 'Planned Print', 'importance' => 'secondary', 'status' => 'planned', 'type' => 'print']);
        $this->declare(['display_name' => 'Occasional Events', 'importance' => 'secondary', 'status' => 'occasional', 'type' => 'events']);

        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertSame(['Planned Print', 'Occasional Events'], $summary->secondaryChannels);
        $this->assertSame([], $summary->inactiveChannels);
    }

    public function test_primary_objectives_come_from_primary_channels_when_present(): void
    {
        $this->declare(['display_name' => 'Instagram', 'importance' => 'primary', 'objective' => ['awareness', 'community']]);
        $this->declare(['display_name' => 'Facebook', 'importance' => 'secondary', 'objective' => ['leads'], 'type' => 'facebook']);

        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertSame(['awareness', 'community'], $summary->primaryObjectives);
    }

    public function test_primary_objectives_fall_back_to_active_channels_when_no_primary_declared(): void
    {
        $this->declare(['display_name' => 'Facebook', 'importance' => 'secondary', 'objective' => ['leads']]);

        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertSame(['leads'], $summary->primaryObjectives);
    }

    public function test_primary_objectives_are_deduplicated(): void
    {
        $this->declare(['display_name' => 'Instagram', 'importance' => 'primary', 'objective' => ['awareness'], 'type' => 'instagram']);
        $this->declare(['display_name' => 'Email', 'importance' => 'primary', 'objective' => ['awareness', 'retention'], 'type' => 'email']);

        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertSame(['awareness', 'retention'], $summary->primaryObjectives);
    }

    public function test_summary_sentence_mentions_every_populated_bucket(): void
    {
        $this->declare(['display_name' => 'Instagram', 'importance' => 'primary', 'status' => 'active', 'objective' => ['awareness']]);
        $this->declare(['display_name' => 'Facebook', 'importance' => 'secondary', 'status' => 'active', 'type' => 'facebook', 'objective' => ['community']]);
        $this->declare(['display_name' => 'Old X', 'importance' => 'primary', 'status' => 'inactive', 'type' => 'x', 'objective' => ['awareness']]);

        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertStringContainsString('Primary marketing channels: Instagram.', $summary->summary);
        $this->assertStringContainsString('Secondary marketing channels: Facebook.', $summary->summary);
        $this->assertStringContainsString('No longer active on: Old X.', $summary->summary);
        $this->assertStringContainsString('Primary marketing objectives: awareness.', $summary->summary);
    }

    public function test_is_scoped_to_the_given_company(): void
    {
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'type' => 'instagram',
            'display_name' => "Other Co's Instagram",
            'importance' => 'primary',
            'objective' => ['awareness'],
        ]);

        $summary = $this->synthesizer->synthesize($this->company->id);

        $this->assertSame([], $summary->primaryChannels);
        $this->assertSame('No marketing channels have been declared yet.', $summary->summary);
    }

    /** @param array<string, mixed> $overrides */
    private function declare(array $overrides = []): MarketingChannel
    {
        return MarketingChannel::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'display_name' => 'Channel',
            'objective' => ['awareness'],
        ], $overrides));
    }
}
