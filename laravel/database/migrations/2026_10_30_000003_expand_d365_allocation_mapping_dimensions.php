<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('d365_allocation_mappings', function (Blueprint $table): void {
            $table->string('group_activity_code', 64)->nullable()->after('account_prefix');
            $table->string('group_activity_name', 160)->nullable()->after('group_activity_code');
            $table->string('activity_code', 64)->nullable()->after('activity');
            $table->string('activity_name', 160)->nullable()->after('activity_code');
            $table->string('location_code', 64)->nullable()->after('location');
            $table->string('location_name', 160)->nullable()->after('location_code');
            $table->index(['group_activity_code', 'activity_code', 'location_code'], 'd365_mapping_dimension_codes');
        });
    }

    public function down(): void
    {
        Schema::table('d365_allocation_mappings', function (Blueprint $table): void {
            $table->dropIndex('d365_mapping_dimension_codes');
            $table->dropColumn([
                'group_activity_code', 'group_activity_name',
                'activity_code', 'activity_name',
                'location_code', 'location_name',
            ]);
        });
    }
};
