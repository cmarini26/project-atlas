<?php

namespace Tests\Feature\Onboarding;

use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\Onboarding\OnboardingAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    private OnboardingAssetService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(OnboardingAssetService::class);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_sync_enabled_assets_creates_a_channel_per_enabled_type(): void
    {
        $result = $this->service->syncEnabledAssets($this->company, ['website', 'instagram'], []);

        $this->assertCount(2, $result);
        $this->assertSame(2, MarketingChannel::where('company_id', $this->company->id)->count());
    }

    public function test_sync_enabled_assets_marks_primary_types_correctly(): void
    {
        $this->service->syncEnabledAssets($this->company, ['website', 'instagram', 'facebook'], ['website', 'instagram']);

        $website = MarketingChannel::where('company_id', $this->company->id)->where('type', 'website')->first();
        $instagram = MarketingChannel::where('company_id', $this->company->id)->where('type', 'instagram')->first();
        $facebook = MarketingChannel::where('company_id', $this->company->id)->where('type', 'facebook')->first();

        $this->assertSame('primary', $website->importance->value);
        $this->assertSame('primary', $instagram->importance->value);
        $this->assertSame('secondary', $facebook->importance->value);
    }

    public function test_sync_enabled_assets_removes_disabled_types(): void
    {
        $this->service->syncEnabledAssets($this->company, ['website', 'instagram'], []);
        $this->service->syncEnabledAssets($this->company, ['website'], []);

        $this->assertSame(1, MarketingChannel::where('company_id', $this->company->id)->count());
        $this->assertDatabaseMissing('marketing_channels', ['company_id' => $this->company->id, 'type' => 'instagram']);
    }

    public function test_sync_enabled_assets_updates_importance_on_resubmit_without_duplicating(): void
    {
        $this->service->syncEnabledAssets($this->company, ['website'], ['website']);
        $this->service->syncEnabledAssets($this->company, ['website'], []);

        $this->assertSame(1, MarketingChannel::where('company_id', $this->company->id)->count());
        $channel = MarketingChannel::where('company_id', $this->company->id)->first();
        $this->assertSame('secondary', $channel->importance->value);
    }

    public function test_sync_enabled_assets_sets_display_name_from_the_type_label(): void
    {
        $this->service->syncEnabledAssets($this->company, ['google_business_profile'], []);

        $channel = MarketingChannel::where('company_id', $this->company->id)->first();
        $this->assertSame('Google Business Profile', $channel->display_name);
    }

    public function test_save_asset_details_persists_website_url_and_platform(): void
    {
        $this->service->syncEnabledAssets($this->company, ['website'], []);

        $this->service->saveAssetDetails($this->company, [
            'website' => ['url' => 'https://acme.com', 'platform' => 'shopify'],
        ]);

        $channel = MarketingChannel::where('company_id', $this->company->id)->first();
        $this->assertSame('https://acme.com', $channel->handle_or_url);
        $this->assertSame('shopify', $channel->metadata['platform']);
    }

    public function test_save_asset_details_ignores_types_that_were_never_declared(): void
    {
        $this->service->syncEnabledAssets($this->company, ['website'], []);

        $this->service->saveAssetDetails($this->company, [
            'instagram' => ['url' => 'https://instagram.com/acme'],
        ]);

        $this->assertSame(1, MarketingChannel::where('company_id', $this->company->id)->count());
    }

    public function test_save_asset_details_scopes_per_company(): void
    {
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $this->service->syncEnabledAssets($this->company, ['website'], []);
        $this->service->syncEnabledAssets($other, ['website'], []);

        $this->service->saveAssetDetails($this->company, [
            'website' => ['url' => 'https://acme.com', 'platform' => 'custom'],
        ]);

        $otherChannel = MarketingChannel::where('company_id', $other->id)->first();
        $this->assertNull($otherChannel->handle_or_url);
    }
}
