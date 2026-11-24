<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_sheets_inventory_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7)->unique();
            $table->decimal('overhead_pool_usd', 19, 6)->default(0);
            $table->decimal('isp_rent_usd', 19, 6)->default(0);
            $table->decimal('allocated_bua_usd', 19, 6)->default(0);
            $table->decimal('allocated_wash_usd', 19, 6)->default(0);
            $table->decimal('allocated_port_usd', 19, 6)->default(0);
            $table->decimal('allocated_isp_usd', 19, 6)->default(0);
            $table->string('source_sheet', 80)->default('WA COGP-COGS');
            $table->timestamp('source_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheets_inventory_cost_allocations');
    }
};
