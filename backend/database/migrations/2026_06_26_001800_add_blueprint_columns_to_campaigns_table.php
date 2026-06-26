<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->json('blueprint')->nullable()->after('call_to_action');
            $table->string('blueprint_version')->nullable()->after('blueprint');
            $table->string('prompt_version')->nullable()->after('blueprint_version');
            $table->unsignedInteger('expected_asset_count')->default(0)->after('prompt_version');
            $table->unsignedInteger('generated_asset_count')->default(0)->after('expected_asset_count');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'blueprint',
                'blueprint_version',
                'prompt_version',
                'expected_asset_count',
                'generated_asset_count',
            ]);
        });
    }
};
