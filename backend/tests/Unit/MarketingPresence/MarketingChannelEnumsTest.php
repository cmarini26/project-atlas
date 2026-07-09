<?php

namespace Tests\Unit\MarketingPresence;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelObjective;
use App\Enums\MarketingChannelStatus;
use App\Enums\MarketingChannelType;
use App\Enums\PostingFrequency;
use PHPUnit\Framework\TestCase;

class MarketingChannelEnumsTest extends TestCase
{
    public function test_channel_type_has_the_twelve_specified_values(): void
    {
        $this->assertSame(
            [
                'website', 'email', 'instagram', 'facebook', 'linkedin', 'x',
                'youtube', 'tiktok', 'google_business_profile', 'events', 'print', 'other',
            ],
            MarketingChannelType::values(),
        );
    }

    public function test_status_has_the_four_specified_values(): void
    {
        $this->assertSame(
            ['active', 'occasional', 'planned', 'inactive'],
            MarketingChannelStatus::values(),
        );
    }

    public function test_importance_has_the_three_specified_values(): void
    {
        $this->assertSame(
            ['primary', 'secondary', 'experimental'],
            MarketingChannelImportance::values(),
        );
    }

    public function test_objective_has_the_seven_specified_values(): void
    {
        $this->assertSame(
            ['awareness', 'leads', 'sales', 'retention', 'trust', 'seo', 'community'],
            MarketingChannelObjective::values(),
        );
    }

    public function test_posting_frequency_has_the_seven_specified_values(): void
    {
        $this->assertSame(
            ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'rarely', 'unknown'],
            PostingFrequency::values(),
        );
    }

    /**
     * Per specs/core/marketing-presence.md §3 and §6: only these five types
     * have a corresponding App\Models\Channel type today, i.e. only these
     * could ever have channel_id populated.
     */
    public function test_only_types_with_a_real_channel_equivalent_report_true(): void
    {
        $withEquivalent = array_filter(
            MarketingChannelType::cases(),
            fn (MarketingChannelType $type): bool => $type->hasChannelEquivalent(),
        );

        $this->assertSame(
            ['email', 'instagram', 'facebook', 'linkedin', 'x'],
            array_map(fn (MarketingChannelType $type): string => $type->value, array_values($withEquivalent)),
        );
    }

    public function test_types_with_no_channel_equivalent_report_false(): void
    {
        foreach (['website', 'youtube', 'tiktok', 'google_business_profile', 'events', 'print', 'other'] as $value) {
            $this->assertFalse(
                MarketingChannelType::from($value)->hasChannelEquivalent(),
                "Expected {$value} to have no Channel equivalent.",
            );
        }
    }
}
