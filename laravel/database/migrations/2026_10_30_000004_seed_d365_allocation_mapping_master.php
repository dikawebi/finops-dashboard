<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $path = base_path('database/seed-data/d365-allocation-mapping.tsv');
        if (! is_file($path)) return;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $columns = preg_split('/\t+/', trim($line));
            if (count($columns) < 8) continue;
            [$groupCode, $groupName, $activityCode, $activityName, $locationCode, $locationName, $valuationTo, $costingTo] = array_map('trim', array_slice($columns, 0, 8));
            $key = [
                'group_activity_code' => $groupCode,
                'activity_code' => $activityCode,
                'location_code' => $locationCode,
                'valuation_to' => $valuationTo,
                'costing_to' => $costingTo,
            ];
            DB::table('d365_allocation_mappings')->updateOrInsert($key, [
                'group_activity_name' => $groupName,
                'activity_name' => $activityName,
                'location_name' => $locationName,
                'label' => $groupName.' / '.$activityName.' / '.$locationName,
                'priority' => 100,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $path = base_path('database/seed-data/d365-allocation-mapping.tsv');
        if (! is_file($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $columns = preg_split('/\t+/', trim($line));
            if (count($columns) < 8) continue;
            [$groupCode, , $activityCode, , $locationCode, , $valuationTo, $costingTo] = array_map('trim', array_slice($columns, 0, 8));
            DB::table('d365_allocation_mappings')->where([
                'group_activity_code' => $groupCode,
                'activity_code' => $activityCode,
                'location_code' => $locationCode,
                'valuation_to' => $valuationTo,
                'costing_to' => $costingTo,
            ])->delete();
        }
    }
};
