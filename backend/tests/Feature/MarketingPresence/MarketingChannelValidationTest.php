<?php

namespace Tests\Feature\MarketingPresence;

use App\Models\MarketingChannel;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MarketingChannelValidationTest extends TestCase
{
    public function test_valid_attributes_pass(): void
    {
        $validator = Validator::make([
            'type' => 'instagram',
            'display_name' => 'CBB Auctions Instagram',
            'handle_or_url' => '@cbbauctions',
            'status' => 'active',
            'importance' => 'primary',
            'objective' => ['awareness', 'community'],
            'audience' => 'Comic book collectors',
            'posting_frequency' => 'weekly',
            'notes' => null,
        ], MarketingChannel::rules());

        $this->assertTrue($validator->passes());
    }

    public function test_type_must_be_a_known_enum_value(): void
    {
        $validator = Validator::make(
            $this->validAttributes(['type' => 'snapchat']),
            MarketingChannel::rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('type', $validator->errors()->toArray());
    }

    public function test_display_name_is_required(): void
    {
        $attributes = $this->validAttributes();
        unset($attributes['display_name']);

        $validator = Validator::make($attributes, MarketingChannel::rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('display_name', $validator->errors()->toArray());
    }

    public function test_status_must_be_a_known_enum_value(): void
    {
        $validator = Validator::make(
            $this->validAttributes(['status' => 'somewhat_active']),
            MarketingChannel::rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    public function test_importance_must_be_a_known_enum_value(): void
    {
        $validator = Validator::make(
            $this->validAttributes(['importance' => 'critical']),
            MarketingChannel::rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('importance', $validator->errors()->toArray());
    }

    public function test_objective_requires_at_least_one_value(): void
    {
        $validator = Validator::make(
            $this->validAttributes(['objective' => []]),
            MarketingChannel::rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('objective', $validator->errors()->toArray());
    }

    public function test_objective_rejects_an_unknown_value_in_the_array(): void
    {
        $validator = Validator::make(
            $this->validAttributes(['objective' => ['awareness', 'virality']]),
            MarketingChannel::rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('objective.1', $validator->errors()->toArray());
    }

    public function test_posting_frequency_is_nullable(): void
    {
        $validator = Validator::make(
            $this->validAttributes(['posting_frequency' => null]),
            MarketingChannel::rules(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_posting_frequency_must_be_a_known_enum_value_when_present(): void
    {
        $validator = Validator::make(
            $this->validAttributes(['posting_frequency' => 'hourly']),
            MarketingChannel::rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('posting_frequency', $validator->errors()->toArray());
    }

    /** @param  array<string, mixed>  $overrides
     * @return array<string, mixed> */
    private function validAttributes(array $overrides = []): array
    {
        return array_merge([
            'type' => 'instagram',
            'display_name' => 'Test Channel',
            'handle_or_url' => null,
            'status' => 'active',
            'importance' => 'secondary',
            'objective' => ['awareness'],
            'audience' => null,
            'posting_frequency' => 'unknown',
            'notes' => null,
        ], $overrides);
    }
}
