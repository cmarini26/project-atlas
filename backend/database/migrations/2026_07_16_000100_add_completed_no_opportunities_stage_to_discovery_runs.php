<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Milestone 15 Phase 3 — adds the 'completed_no_opportunities' DiscoveryRun
 * stage: every attempted connector finished, at least one succeeded, Atlas
 * understood the business, but no Opportunity/Recommendation ever resulted.
 * A legitimate, final outcome distinct from completed_with_errors (nothing
 * observable at all worked) — never an indefinite "Recommend" spinner.
 *
 * The base create_discovery_runs_table migration was updated to include
 * this value for fresh databases (including the sqlite test database).
 * This migration only rewrites the enum check constraint on existing
 * PostgreSQL databases, mirroring add_retrying_status_to_observations.php's
 * precedent exactly.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE discovery_runs DROP CONSTRAINT IF EXISTS discovery_runs_stage_check');
        DB::statement("ALTER TABLE discovery_runs ADD CONSTRAINT discovery_runs_stage_check CHECK (stage::text = ANY (ARRAY['discovering'::text, 'analyzing'::text, 'understanding'::text, 'recommending'::text, 'completed'::text, 'completed_with_errors'::text, 'completed_no_opportunities'::text]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("UPDATE discovery_runs SET stage = 'completed_with_errors' WHERE stage = 'completed_no_opportunities'");
        DB::statement('ALTER TABLE discovery_runs DROP CONSTRAINT IF EXISTS discovery_runs_stage_check');
        DB::statement("ALTER TABLE discovery_runs ADD CONSTRAINT discovery_runs_stage_check CHECK (stage::text = ANY (ARRAY['discovering'::text, 'analyzing'::text, 'understanding'::text, 'recommending'::text, 'completed'::text, 'completed_with_errors'::text]))");
    }
};
