<?php

namespace Tests\Unit\Opportunity;

use App\Services\Opportunity\OpportunityCandidate;
use App\Services\Opportunity\OpportunityScorer;
use PHPUnit\Framework\TestCase;

class OpportunityScorerWeightTest extends TestCase
{
    private OpportunityScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new OpportunityScorer();
    }

    private function makeCandidate(string $type = 'featured_item', int $score = 80): OpportunityCandidate
    {
        return new OpportunityCandidate(
            type: $type,
            subjectType: null,
            subjectId: null,
            title: 'Test',
            description: 'Test',
            expiresAt: null,
            relevanceScore: $score,
            timingScore: $score,
            confidenceScore: $score,
            urgencyScore: $score,
        );
    }

    public function test_no_modifiers_produces_baseline_score(): void
    {
        $candidate = $this->makeCandidate('featured_item', 80);

        $result = $this->scorer->score($candidate, null);

        $this->assertNotNull($result);
        // (80*0.30)+(80*0.25)+(80*0.25)+(80*0.20) = 80
        $this->assertSame(80, $result['composite']);
    }

    public function test_positive_modifier_increases_composite(): void
    {
        $candidate = $this->makeCandidate('featured_item', 80);

        $result = $this->scorer->score($candidate, ['featured_item' => 1.05]);

        $this->assertNotNull($result);
        $this->assertGreaterThan(80, $result['composite']);
    }

    public function test_negative_modifier_decreases_composite(): void
    {
        $candidate = $this->makeCandidate('featured_item', 80);

        $result = $this->scorer->score($candidate, ['featured_item' => 0.90]);

        $this->assertNotNull($result);
        $this->assertLessThan(80, $result['composite']);
    }

    public function test_modifier_only_applies_to_matching_type(): void
    {
        $featured = $this->makeCandidate('featured_item', 80);
        $urgency = $this->makeCandidate('urgency', 80);

        $modifiers = ['featured_item' => 1.10];

        $featuredResult = $this->scorer->score($featured, $modifiers);
        $urgencyResult = $this->scorer->score($urgency, $modifiers);

        $this->assertNotNull($featuredResult);
        $this->assertNotNull($urgencyResult);

        $this->assertGreaterThan($urgencyResult['composite'], $featuredResult['composite']);
    }

    public function test_existing_scorer_works_without_modifier_param(): void
    {
        $candidate = $this->makeCandidate('featured_item', 80);

        // Existing interface: no second arg
        $result = $this->scorer->score($candidate);

        $this->assertNotNull($result);
        $this->assertSame(80, $result['composite']);
    }

    public function test_modifier_that_pushes_below_minimum_returns_null(): void
    {
        $candidate = $this->makeCandidate('featured_item', 35);

        // (35*0.30)+(35*0.25)+(35*0.25)+(35*0.20) = 35 base, *0.8 = 28 < 30
        $result = $this->scorer->score($candidate, ['featured_item' => 0.80]);

        $this->assertNull($result);
    }

    public function test_unknown_type_modifier_is_ignored(): void
    {
        $candidate = $this->makeCandidate('featured_item', 80);

        $result = $this->scorer->score($candidate, ['some_other_type' => 1.50]);

        $this->assertNotNull($result);
        $this->assertSame(80, $result['composite']);
    }
}
