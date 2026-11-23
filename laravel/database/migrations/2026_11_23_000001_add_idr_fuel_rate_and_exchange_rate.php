<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_fuel_rates', function (Blueprint $table): void {
            $table->decimal('rate_idr_per_litre', 18, 4)->nullable();
            $table->decimal('exchange_rate_idr_per_usd', 18, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_fuel_rates', function (Blueprint $table): void {
            $table->dropColumn(['rate_idr_per_litre', 'exchange_rate_idr_per_usd']);
        });
    }
};
