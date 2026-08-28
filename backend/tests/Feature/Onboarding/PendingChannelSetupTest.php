<?php

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\PendingChannelSetup;
use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingChannelSetupTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
    }

    /** @param array<string, mixed> $overrides */
    private function channel(string $type, array $overrides = []): MarketingChannel
    {
        return MarketingChannel::create([
            'company_id' => $this->company->id,
            'type' => $type,
            'display_name' => ucfirst($type),
            'status' => 'active',
            'importance' => 'secondary',
            'objective' => ['awareness'],
            'is_connected' => false,
            ...$overrides,
        ]);
    }

    public function test_lists_declared_but_unconnected_handle_and_oauth_channels(): void
    {
        $this->channel('instagram');                          // oauth, not connected
        $this->channel('x');                                  // handle, no handle_or_url
        $this->channel('website', ['handle_or_url' => 'https://a.test']); // none — excluded
        $this->channel('events');                             // manual — excluded

        $pending = PendingChannelSetup::forCompany($this->company);
        $types = array_column($pending, 'type');

        sort($types);
        $this->assertSame(['instagram', 'x'], $types);
        $this->assertSame('oauth', $pending[array_search('instagram', $types, true)]['requirement']);
        $this->assertStringContainsString('Marketing Presence', $pending[0]['summary']);
    }

    public function test_a_handle_channel_is_satisfied_once_a_url_is_entered(): void
    {
        $this->channel('linkedin', ['handle_or_url' => 'https://linkedin.com/company/acme']);

        $this->assertSame([], PendingChannelSetup::forCompany($this->company));
    }

    public function test_an_oauth_channel_is_satisfied_once_connected(): void
    {
        $this->channel('facebook', ['is_connected' => true]);

        $this->assertFalse(PendingChannelSetup::hasPending($this->company));
    }

    public function test_reconnecting_a_channel_later_removes_it_from_the_list(): void
    {
        $channel = $this->channel('instagram');
        $this->assertTrue(PendingChannelSetup::hasPending($this->company));

        $channel->update(['is_connected' => true]);
        $this->assertFalse(PendingChannelSetup::hasPending($this->company));
    }
}
