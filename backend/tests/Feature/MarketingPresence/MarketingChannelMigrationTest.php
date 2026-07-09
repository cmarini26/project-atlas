<?php

namespace Tests\Feature\MarketingPresence;

use App\Models\Channel;
use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketingChannelMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_channels_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('marketing_channels'));
    }

    public function test_table_has_every_column_specified_in_the_spec(): void
    {
        $this->assertTrue(Schema::hasColumns('marketing_channels', [
            'id',
            'company_id',
            'channel_id',
            'type',
            'display_name',
            'handle_or_url',
            'status',
            'importance',
            'objective',
            'audience',
            'posting_frequency',
            'notes',
            'is_connected',
            'supports_publishing',
            'supports_analytics',
            'metadata',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_table_has_no_soft_delete_column(): void
    {
        // specs/core/marketing-presence.md §2: "No soft deletes." An
        // inactive-but-undeleted channel is represented by status: inactive.
        $this->assertFalse(Schema::hasColumn('marketing_channels', 'deleted_at'));
    }

    public function test_company_id_foreign_key_cascades_on_delete(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Cascade Co',
            'slug' => 'cascade-co',
        ]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'display_name' => 'Cascade Instagram',
            'objective' => ['awareness'],
        ]);

        $company->forceDelete(); // Company uses SoftDeletes — force a hard delete to prove the FK cascade.

        $this->assertDatabaseCount('marketing_channels', 0);
    }

    public function test_channel_id_foreign_key_nulls_on_delete(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Null Co',
            'slug' => 'null-co',
        ]);

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);

        $marketingChannel = MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_id' => $channel->id,
            'type' => 'email',
            'display_name' => 'Email Newsletter',
            'objective' => ['retention'],
        ]);

        DB::table('channels')->where('id', $channel->id)->delete();

        $this->assertNull($marketingChannel->fresh()->channel_id);
    }

    public function test_type_column_rejects_a_value_outside_the_enum(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Invalid Co',
            'slug' => 'invalid-co',
        ]);

        $this->expectException(QueryException::class);

        DB::table('marketing_channels')->insert([
            'id' => (string) Str::ulid(),
            'company_id' => $company->id,
            'type' => 'not_a_real_type',
            'display_name' => 'Invalid',
            'objective' => json_encode(['awareness']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
