<?php

namespace Database\Factories;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelObjective;
use App\Enums\MarketingChannelStatus;
use App\Enums\MarketingChannelType;
use App\Enums\PostingFrequency;
use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingChannel>
 */
class MarketingChannelFactory extends Factory
{
    protected $model = MarketingChannel::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'channel_id' => null,
            'type' => $this->faker->randomElement(MarketingChannelType::values()),
            'display_name' => ucfirst($this->faker->words(2, true)),
            'handle_or_url' => $this->faker->boolean(70) ? '@'.$this->faker->userName() : null,
            'status' => MarketingChannelStatus::Active->value,
            'importance' => MarketingChannelImportance::Secondary->value,
            'objective' => [MarketingChannelObjective::Awareness->value],
            'audience' => null,
            'posting_frequency' => PostingFrequency::Unknown->value,
            'notes' => null,
            'is_connected' => false,
            'supports_publishing' => false,
            'supports_analytics' => false,
            'metadata' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['importance' => MarketingChannelImportance::Primary->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => MarketingChannelStatus::Inactive->value]);
    }
}
