<?php

use App\Models\DashboardRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_roles', function (Blueprint $table) {
            $table->string('slug', 32)->primary();
            $table->string('name', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->json('page_access');
            $table->boolean('is_system')->default(false)->index();
            $table->timestamps();
        });

        $pages = array_keys(DashboardRole::pages());
        $administratorAccess = array_fill_keys($pages, 'edit');
        $viewerAccess = array_fill_keys(['overview', 'cost', 'variance', 'metric', 'equipmentCost', 'inventory', 'financial', 'glReview'], 'read');
        $now = now();
        DB::table('dashboard_roles')->insert([
            ['slug' => 'administrator', 'name' => 'Administrator', 'description' => 'Full access to all pages and actions.', 'page_access' => json_encode($administratorAccess), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'viewer', 'name' => 'Viewer', 'description' => 'Read-only access to operational and financial reports.', 'page_access' => json_encode($viewerAccess), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_roles');
    }
};
