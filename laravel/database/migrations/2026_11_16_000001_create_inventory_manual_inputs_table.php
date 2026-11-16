<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_manual_inputs', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7)->index();
            $table->string('input_type', 20);
            $table->string('location', 40);
            $table->string('reference', 100);
            $table->decimal('qty_mt', 19, 6)->default(0);
            $table->decimal('adjustment_usd', 19, 6)->default(0);
            $table->decimal('sale_price_usd_per_mt', 19, 6)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['period', 'input_type', 'location', 'reference'], 'inventory_manual_input_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_manual_inputs');
    }
};
