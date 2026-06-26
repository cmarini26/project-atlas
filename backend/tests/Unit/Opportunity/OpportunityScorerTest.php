<?php

namespace Tests\Unit\Opportunity;

use App\Services\Opportunity\OpportunityCandidate;
use App\Services\Opportunity\OpportunityScorer;
use PHPUnit\Framework\TestCase;

class OpportunityScorerTest extends TestCase
{
    private OpportunityScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new OpportunityScorer();
    }

    public function test_below_minimum_threshold_returns_null(): void
    {
        // All zeros → composite = 0
        $candidate = new OpportunityCandidate(
            type: 'featured_item',
            subjectType: null,
            subjectId: null,
            title: 'Test',
            description: 'Test',
            expiresAt: null,
            relevanceScore: 0,
            timingScore: 0,
            confidenceScore: 0,
            urgencyScore: 0,
        );

        $this->assertNull($this->scorer->score($candidate));
    }

    public function test_at_minimum_threshold_returns_scores(): void
    {
        // Exactly at threshold: (40*0.30)+(40*0.25)+(40*0.25)+(0*0.20) = 12+10+10 = 32
        $candidate = new OpportunityCandidate(
            type: 'featured_item',
            subjectType: null,
            subjectId: null,
            title: 'Test',
            description: 'Test',
            expiresAt: null,
            relevanceScore: 40,
            timingScore: 40,
            confidenceScore: 40,
            urgencyScore: 0,
        );

        $scores = $this->scorer->score($candidate);

        $this->assertNotNull($scores);
        $this->assertArrayHasKey('relevance', $scores);
        $this->assertArrayHasKey('timing', $scores);
        $this->assertArrayHasKey('confidence', $scores);
        $this->assertArrayHasKey('urgency', $scores);
        $this->assertArrayHasKey('composite', $scores);
        $this->assertGreaterThanOrEqual(30, $scores['composite']);
    }

    public function test_caps_ai_confidence_score_at_75(): void
    {
        $candidate = new OpportunityCandidate(
            type: 'seasonal',
            subjectType: null,
            subjectId: null,
            title: 'Test',
            description: 'Test',
            expiresAt: null,
            relevanceScore: 80,
            timingScore: 80,
            confidenceScore: 95, // over the cap
            urgencyScore: 80,
            aiDetected: true,
        );

        $scores = $this->scorer->score($candidate);

        $this->assertNotNull($scores);
        $this->assertSame(75, $scores['confidence']);
    }

    public function test_does_not_cap_confidence_for_rule_based_candidates(): void
    {
        $candidate = new OpportunityCandidate(
            type: 'urgency',
            subjectType: null,
            subjectId: null,
            title: 'Test',
            description: 'Test',
            expiresAt: null,
            relevanceScore: 85,
            timingScore: 95,
            confidenceScore: 90,
            urgencyScore: 98,
            aiDetected: false,
        );

        $scores = $this->scorer->score($candidate);

        $this->assertNotNull($scores);
        $this->assertSame(90, $scores['confidence']);
    }

    public function test_formula_calculates_weighted_composite(): void
    {
        // (80*0.30) + (70*0.25) + (60*0.25) + (50*0.20) = 24 + 17.5 + 15 + 10 = 66.5 → 67
        $candidate = new OpportunityCandidate(
            type: 'featured_item',
            subjectType: null,
            subjectId: null,
            title: 'Test',
            description: 'Test',
            expiresAt: null,
            relevanceScore: 80,
            timingScore: 70,
            confidenceScore: 60,
            urgencyScore: 50,
        );

        $scores = $this->scorer->score($candidate);

        $this->assertNotNull($scores);
        $this->assertSame(67, $scores['composite']);
    }

    public function test_clamps_scores_to_100(): void
    {
        $candidate = new OpportunityCandidate(
            type: 'urgency',
            subjectType: null,
            subjectId: null,
            title: 'Test',
            description: 'Test',
            expiresAt: null,
            relevanceScore: 150, // over 100
            timingScore: 200,
            confidenceScore: 999,
            urgencyScore: 110,
        );

        $scores = $this->scorer->score($candidate);

        $this->assertNotNull($scores);
        $this->assertSame(100, $scores['relevance']);
        $this->assertSame(100, $scores['timing']);
        $this->assertSame(100, $scores['confidence']);
        $this->assertSame(100, $scores['urgency']);
    }
}
