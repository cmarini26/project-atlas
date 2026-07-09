<?php

namespace Tests\Feature\MarketingPresence;

use App\Models\Channel;
use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingChannelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_company(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Rel Co', 'slug' => 'rel-co']);

        $marketingChannel = MarketingChannel::create([
            'company_id' => $company->id,
            'type' => 'website',
            'display_name' => 'Company Website',
            'objective' => ['seo'],
        ]);

        $this->assertTrue($marketingChannel->company->is($company));
    }

    public function test_belongs_to_channel_when_linked(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Rel Co', 'slug' => 'rel-co-2']);

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);

        $marketingChannel = MarketingChannel::create([
            'company_id' => $company->id,
            'channel_id' => $channel->id,
            'type' => 'email',
            'display_name' => 'Email Newsletter',
            'objective' => ['retention'],
        ]);

        $this->assertNotNull($marketingChannel->channel);
        $this->assertTrue($marketingChannel->channel->is($channel));
    }

    public function test_channel_relationship_is_null_when_not_linked(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Rel Co', 'slug' => 'rel-co-3']);

        $marketingChannel = MarketingChannel::create([
            'company_id' => $company->id,
            'type' => 'print',
            'display_name' => 'Local Paper Ad',
            'objective' => ['awareness'],
        ]);

        $this->assertNull($marketingChannel->channel_id);
        $this->assertNull($marketingChannel->channel);
    }

    public function test_company_has_many_marketing_channels(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Rel Co', 'slug' => 'rel-co-4']);

        MarketingChannel::create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'display_name' => 'Instagram',
            'objective' => ['awareness'],
        ]);

        MarketingChannel::create([
            'company_id' => $company->id,
            'type' => 'events',
            'display_name' => 'Trade Shows',
            'objective' => ['trust'],
        ]);

        $this->assertCount(2, $company->marketingChannels);
    }
}
