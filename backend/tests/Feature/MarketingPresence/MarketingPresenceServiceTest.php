<?php

namespace Tests\Feature\MarketingPresence;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelStatus;
use App\Events\MarketingPresenceUpdated;
use App\Models\Channel;
use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\MarketingPresence\Exceptions\ChannelBelongsToDifferentCompanyException;
use App\Services\MarketingPresence\MarketingPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketingPresenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingPresenceService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MarketingPresenceService();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    // ── declare() ────────────────────────────────────────────────────────────

    public function test_declare_creates_a_marketing_channel(): void
    {
        $channel = $this->service->declare($this->company, [
            'type' => 'instagram',
            'display_name' => 'Acme Instagram',
        ]);

        $this->assertDatabaseHas('marketing_channels', [
            'id' => $channel->id,
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'display_name' => 'Acme Instagram',
        ]);
    }

    public function test_declare_fills_in_suggested_defaults_when_omitted(): void
    {
        $channel = $this->service->declare($this->company, [
            'type' => 'email',
            'display_name' => 'Newsletter',
        ]);

        $this->assertSame(MarketingChannelImportance::Primary, $channel->importance);
        $this->assertSame(['retention', 'sales'], $channel->objective);
        $this->assertSame(MarketingChannelStatus::Active, $channel->status);
    }

    public function test_declare_allows_caller_to_override_defaults(): void
    {
        $channel = $this->service->declare($this->company, [
            'type' => 'email',
            'display_name' => 'Newsletter',
            'importance' => 'experimental',
            'objective' => ['awareness'],
        ]);

        $this->assertSame(MarketingChannelImportance::Experimental, $channel->importance);
        $this->assertSame(['awareness'], $channel->objective);
    }

    public function test_declare_ignores_caller_supplied_company_id(): void
    {
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        $channel = $this->service->declare($this->company, [
            'type' => 'website',
            'display_name' => 'Site',
            'company_id' => $otherCompany->id,
        ]);

        $this->assertSame($this->company->id, $channel->company_id);
    }

    public function test_declare_never_links_a_channel_even_if_channel_id_is_passed(): void
    {
        $realChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);

        $channel = $this->service->declare($this->company, [
            'type' => 'email',
            'display_name' => 'Newsletter',
            'channel_id' => $realChannel->id,
            'is_connected' => true,
        ]);

        $this->assertNull($channel->channel_id);
        $this->assertFalse($channel->is_connected);
    }

    public function test_declare_dispatches_marketing_presence_updated(): void
    {
        Event::fake();

        $channel = $this->service->declare($this->company, [
            'type' => 'website',
            'display_name' => 'Site',
        ]);

        Event::assertDispatched(MarketingPresenceUpdated::class, fn ($event) => $event->marketingChannel->is($channel));
    }

    public function test_declare_rejects_invalid_type(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->declare($this->company, [
            'type' => 'not-a-real-type',
            'display_name' => 'Bad',
        ]);
    }

    public function test_declare_rejects_missing_display_name(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->declare($this->company, ['type' => 'website']);
    }

    public function test_declare_allows_multiple_channels_of_the_same_type(): void
    {
        $this->service->declare($this->company, ['type' => 'instagram', 'display_name' => 'Main Instagram']);
        $second = $this->service->declare($this->company, ['type' => 'instagram', 'display_name' => 'Regional Instagram']);

        $this->assertSame('instagram', $second->type->value);
        $this->assertCount(2, MarketingChannel::withoutGlobalScopes()->where('company_id', $this->company->id)->get());
    }

    public function test_declare_allows_duplicate_handle_across_types(): void
    {
        $this->service->declare($this->company, [
            'type' => 'instagram',
            'display_name' => 'Instagram',
            'handle_or_url' => '@acme',
        ]);

        $second = $this->service->declare($this->company, [
            'type' => 'x',
            'display_name' => 'X',
            'handle_or_url' => '@acme',
        ]);

        $this->assertSame('@acme', $second->handle_or_url);
    }

    // ── update() ─────────────────────────────────────────────────────────────

    public function test_update_changes_structural_fields(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'website', 'display_name' => 'Site']);

        $updated = $this->service->update($channel, ['display_name' => 'New Name', 'audience' => 'Local shoppers']);

        $this->assertSame('New Name', $updated->display_name);
        $this->assertSame('Local shoppers', $updated->audience);
    }

    public function test_update_revalidates_the_full_resulting_state(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'website', 'display_name' => 'Site']);

        $this->expectException(ValidationException::class);

        $this->service->update($channel, ['objective' => []]);
    }

    public function test_update_ignores_company_id_and_channel_id(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'website', 'display_name' => 'Site']);
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $realChannel = Channel::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true]);

        $updated = $this->service->update($channel, [
            'company_id' => $otherCompany->id,
            'channel_id' => $realChannel->id,
            'display_name' => 'Still Site',
        ]);

        $this->assertSame($this->company->id, $updated->company_id);
        $this->assertNull($updated->channel_id);
    }

    public function test_update_ignores_lifecycle_booleans(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'website', 'display_name' => 'Site']);

        $updated = $this->service->update($channel, [
            'display_name' => 'Still Site',
            'is_connected' => true,
            'supports_publishing' => true,
            'supports_analytics' => true,
        ]);

        $this->assertFalse($updated->is_connected);
        $this->assertFalse($updated->supports_publishing);
        $this->assertFalse($updated->supports_analytics);
    }

    public function test_update_dispatches_marketing_presence_updated(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'website', 'display_name' => 'Site']);

        Event::fake();

        $this->service->update($channel, ['display_name' => 'New Name']);

        Event::assertDispatched(MarketingPresenceUpdated::class);
    }

    // ── setStatus / disable / reactivate ────────────────────────────────────

    public function test_set_status_updates_status(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'website', 'display_name' => 'Site']);

        $updated = $this->service->setStatus($channel, 'occasional');

        $this->assertSame(MarketingChannelStatus::Occasional, $updated->status);
    }

    public function test_disable_sets_status_inactive(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'website', 'display_name' => 'Site']);

        $updated = $this->service->disable($channel);

        $this->assertSame(MarketingChannelStatus::Inactive, $updated->status);
    }

    public function test_reactivate_sets_status_active(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'website', 'display_name' => 'Site']);
        $this->service->disable($channel);

        $updated = $this->service->reactivate($channel);

        $this->assertSame(MarketingChannelStatus::Active, $updated->status);
    }

    // ── link() ───────────────────────────────────────────────────────────────

    public function test_link_sets_channel_id_and_is_connected(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'email', 'display_name' => 'Newsletter']);
        $realChannel = Channel::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true]);

        $linked = $this->service->link($channel, $realChannel);

        $this->assertSame($realChannel->id, $linked->channel_id);
        $this->assertTrue($linked->is_connected);
    }

    public function test_link_does_not_set_publishing_or_analytics_flags(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'email', 'display_name' => 'Newsletter']);
        $realChannel = Channel::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true]);

        $linked = $this->service->link($channel, $realChannel);

        $this->assertFalse($linked->supports_publishing);
        $this->assertFalse($linked->supports_analytics);
    }

    public function test_link_throws_when_channel_belongs_to_a_different_company(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'email', 'display_name' => 'Newsletter']);
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $realChannel = Channel::withoutGlobalScopes()->create(['company_id' => $otherCompany->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true]);

        $this->expectException(ChannelBelongsToDifferentCompanyException::class);

        $this->service->link($channel, $realChannel);
    }

    public function test_link_throws_when_channel_is_a_global_template_with_null_company(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'email', 'display_name' => 'Newsletter']);
        $globalChannel = Channel::withoutGlobalScopes()->create(['company_id' => null, 'type' => 'email', 'name' => 'Global Email Template', 'is_active' => true]);

        $this->expectException(ChannelBelongsToDifferentCompanyException::class);

        $this->service->link($channel, $globalChannel);
    }

    // ── wouldDuplicate() ─────────────────────────────────────────────────────

    public function test_would_duplicate_is_false_when_handle_is_null(): void
    {
        $this->assertFalse($this->service->wouldDuplicate($this->company, 'instagram', null));
    }

    public function test_would_duplicate_detects_same_type_and_handle(): void
    {
        $this->service->declare($this->company, ['type' => 'instagram', 'display_name' => 'Main', 'handle_or_url' => '@acme']);

        $this->assertTrue($this->service->wouldDuplicate($this->company, 'instagram', '@acme'));
    }

    public function test_would_duplicate_is_false_for_different_type_with_same_handle(): void
    {
        $this->service->declare($this->company, ['type' => 'instagram', 'display_name' => 'Main', 'handle_or_url' => '@acme']);

        $this->assertFalse($this->service->wouldDuplicate($this->company, 'x', '@acme'));
    }

    public function test_would_duplicate_excludes_the_given_id(): void
    {
        $channel = $this->service->declare($this->company, ['type' => 'instagram', 'display_name' => 'Main', 'handle_or_url' => '@acme']);

        $this->assertFalse($this->service->wouldDuplicate($this->company, 'instagram', '@acme', $channel->id));
    }

    // ── tenant isolation ─────────────────────────────────────────────────────

    public function test_declare_and_update_never_leak_across_companies(): void
    {
        $companyA = $this->company;
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'B', 'slug' => 'b-co']);

        $a = $this->service->declare($companyA, ['type' => 'website', 'display_name' => 'A Site']);
        $b = $this->service->declare($companyB, ['type' => 'website', 'display_name' => 'B Site']);

        $this->assertNotSame($a->company_id, $b->company_id);

        $this->app->instance('current_company_id', $companyA->id);

        $this->assertCount(1, MarketingChannel::all());
        $this->assertNull(MarketingChannel::find($b->id));
    }

    public function test_would_duplicate_is_scoped_to_the_given_company(): void
    {
        $companyA = $this->company;
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'B', 'slug' => 'b-co']);

        $this->service->declare($companyA, ['type' => 'instagram', 'display_name' => 'A', 'handle_or_url' => '@same']);

        $this->assertFalse($this->service->wouldDuplicate($companyB, 'instagram', '@same'));
    }
}
