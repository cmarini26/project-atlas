<?php

namespace Tests\Feature\Analytics;

use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\Execution;
use App\Services\Analytics\FakeAnalyticsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class FakeAnalyticsProviderTest extends TestCase
{
    use RefreshDatabase;

    private function makeCredentials(): ChannelCredentials
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'industry' => 'test',
        ]);

        return ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => json_encode(['api_key' => 'test-key']), 'status' => 'active',
        ]);
    }

    public function test_queued_metrics_returned_by_pull(): void
    {
        $fake = new FakeAnalyticsProvider();
        $fake->queueMetrics(['normalised_reach' => 200, 'normalised_engagement' => 10]);

        $result = $fake->pull('msg-123', $this->makeCredentials());

        $this->assertEquals(200, $result['normalised_reach']);
        $this->assertEquals(10, $result['normalised_engagement']);
    }

    public function test_queued_failure_thrown_by_pull(): void
    {
        $fake = new FakeAnalyticsProvider();
        $fake->queueFailure(new \RuntimeException('API down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API down');

        $fake->pull('msg-123', $this->makeCredentials());
    }

    public function test_assert_pulled_passes_after_one_pull(): void
    {
        $fake = new FakeAnalyticsProvider();
        $fake->queueMetrics(['reach' => 100]);

        $fake->pull('msg-1', $this->makeCredentials());
        $fake->assertPulled(1);
    }

    public function test_assert_not_pulled_passes_with_no_pulls(): void
    {
        $fake = new FakeAnalyticsProvider();
        $fake->assertNotPulled();
    }

    public function test_assert_not_pulled_fails_after_pull(): void
    {
        $fake = new FakeAnalyticsProvider();
        $fake->queueMetrics([]);

        $fake->pull('msg-x', $this->makeCredentials());

        $this->expectException(AssertionFailedError::class);
        $fake->assertNotPulled();
    }

    public function test_supports_all_provider_types(): void
    {
        $fake = new FakeAnalyticsProvider();

        $this->assertTrue($fake->supports('postmark'));
        $this->assertTrue($fake->supports('log'));
        $this->assertTrue($fake->supports('anything'));
    }

    public function test_window_closed_is_true_by_default(): void
    {
        $fake = new FakeAnalyticsProvider();

        $execution = new Execution();
        $this->assertTrue($fake->isWindowClosed($execution));
    }

    public function test_set_window_closed_false(): void
    {
        $fake = new FakeAnalyticsProvider();
        $fake->setWindowClosed(false);

        $execution = new Execution();
        $this->assertFalse($fake->isWindowClosed($execution));
    }

    public function test_normalize_returns_raw_unchanged(): void
    {
        $fake = new FakeAnalyticsProvider();
        $raw = ['normalised_reach' => 100, 'custom_key' => 'value'];

        $this->assertEquals($raw, $fake->normalize($raw));
    }

    public function test_polling_delay_is_zero(): void
    {
        $this->assertEquals(0, (new FakeAnalyticsProvider())->pollingDelayHours());
    }
}
