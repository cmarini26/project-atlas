<?php

namespace Tests\Feature\Analytics;

use App\Jobs\ProcessAnalyticsWebhookEvent;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalyticsWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function loadFixture(string $name): string
    {
        return (string) file_get_contents(
            base_path("tests/Fixtures/Analytics/{$name}.json")
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.postmark.webhook_secret' => '']);
    }

    public function test_accepts_valid_postmark_open_payload_and_returns_200(): void
    {
        Queue::fake();

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co', 'industry' => 'test',
        ]);

        $response = $this->postJson(
            '/api/analytics/webhooks/postmark',
            json_decode($this->loadFixture('postmark-open'), true),
        );

        $response->assertOk();
        $response->assertJsonStructure(['accepted']);
    }

    public function test_dispatches_process_event_job_for_valid_payload(): void
    {
        Queue::fake();

        $this->postJson(
            '/api/analytics/webhooks/postmark',
            json_decode($this->loadFixture('postmark-open'), true),
        );

        Queue::assertPushed(ProcessAnalyticsWebhookEvent::class);
    }

    public function test_unknown_provider_returns_422(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/analytics/webhooks/unknown-provider', []);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_invalid_hmac_returns_401(): void
    {
        Queue::fake();
        config(['services.postmark.webhook_secret' => 'my-secret']);

        $response = $this->postJson(
            '/api/analytics/webhooks/postmark',
            json_decode($this->loadFixture('postmark-open'), true),
            ['X-Postmark-Signature' => 'invalid-sig'],
        );

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }
}
