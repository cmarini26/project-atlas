<?php

namespace Tests\Feature\Console;

use App\Enums\DiscoveryStage;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyStagingProfileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_the_seeded_northwind_profile(): void
    {
        $this->artisan('atlas:seed-staging')->assertSuccessful();

        $this->artisan('atlas:verify-staging')
            ->expectsOutputToContain('Northwind staging profile verification passed.')
            ->expectsOutputToContain('wordpress_connection')
            ->expectsOutputToContain('pending')
            ->assertSuccessful();
    }

    public function test_it_can_require_discovery_and_recommendations_once_present(): void
    {
        $this->artisan('atlas:seed-staging')->assertSuccessful();

        $company = Company::withoutGlobalScopes()->where('name', 'Northwind Skin Studio')->firstOrFail();
        DiscoveryRun::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'stage' => DiscoveryStage::Completed,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        Recommendation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'title' => 'Add a stronger consultation CTA above the fold',
            'summary' => 'Homepage CTA should be more visible.',
            'status' => 'pending',
            'confidence_score' => 78,
            'payload' => ['channel' => 'website'],
        ]);

        $this->artisan('atlas:verify-staging --expect-discovery --expect-recommendation')
            ->expectsOutputToContain('Northwind staging profile verification passed.')
            ->assertSuccessful();
    }

    public function test_it_fails_when_required_discovery_is_missing(): void
    {
        $this->artisan('atlas:seed-staging')->assertSuccessful();

        $this->artisan('atlas:verify-staging --expect-discovery')
            ->expectsOutputToContain('Expected at least one discovery run, but none exists.')
            ->assertFailed();
    }

    public function test_it_rejects_an_unknown_profile(): void
    {
        $this->artisan('atlas:verify-staging does-not-exist')
            ->expectsOutputToContain('Unsupported staging profile [does-not-exist]')
            ->assertFailed();
    }

    public function test_it_reports_connected_wordpress_and_email_when_present(): void
    {
        $this->artisan('atlas:seed-staging')->assertSuccessful();

        $company = Company::withoutGlobalScopes()->where('name', 'Northwind Skin Studio')->firstOrFail();
        Channel::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'email'],
            ['name' => 'Email', 'config' => ['from_email' => 'hello@northwindskinstudio.com'], 'is_active' => true],
        );
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'blog',
            'provider_type' => 'wordpress',
            'credentials' => json_encode(['username' => 'atlas', 'app_password' => 'secret']),
            'status' => 'active',
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'email',
            'provider_type' => 'postmark',
            'credentials' => 'token',
            'status' => 'active',
        ]);

        $this->artisan('atlas:verify-staging')
            ->expectsOutputToContain('connected')
            ->assertSuccessful();
    }
}
