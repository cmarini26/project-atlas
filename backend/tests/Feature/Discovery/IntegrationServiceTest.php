<?php

namespace Tests\Feature\Discovery;

use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use App\Services\Company\CompanyService;
use App\Services\Observatory\IntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(IntegrationService::class);

        $owner = User::factory()->create();
        $this->company = $this->app->make(CompanyService::class)->create($owner, ['name' => 'Test Co']);
    }

    public function test_creates_integration_with_correct_attributes(): void
    {
        Queue::fake();

        $integration = $this->service->create($this->company, 'website_crawl', ['url' => 'https://example.com']);

        $this->assertInstanceOf(Integration::class, $integration);

        $this->assertDatabaseHas('integrations', [
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'status' => 'active',
        ]);
    }

    public function test_stores_url_in_encrypted_config(): void
    {
        Queue::fake();

        $integration = $this->service->create($this->company, 'website_crawl', ['url' => 'https://example.com']);

        $this->assertEquals('https://example.com', $integration->config['url']);
    }

    public function test_sets_next_run_at_seven_days_from_now(): void
    {
        Queue::fake();

        $before = now()->addDays(7)->subSecond();
        $integration = $this->service->create($this->company, 'website_crawl', ['url' => 'https://example.com']);
        $after = now()->addDays(7)->addSecond();

        $this->assertNotNull($integration->next_run_at);
        $this->assertTrue($integration->next_run_at->between($before, $after));
    }

    public function test_does_not_auto_dispatch_sync_job(): void
    {
        // Callers (e.g. OnboardingController) own the dispatch decision —
        // IntegrationService::create() only persists the record.
        Queue::fake();

        $this->service->create($this->company, 'website_crawl', ['url' => 'https://example.com']);

        Queue::assertNothingPushed();
    }

    public function test_uses_default_name_for_website_crawl(): void
    {
        Queue::fake();

        $integration = $this->service->create($this->company, 'website_crawl', ['url' => 'https://example.com']);

        $this->assertEquals('Website', $integration->name);
    }
}
