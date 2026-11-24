<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_sheets_wash_cost_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7);
            $table->string('category', 120);
            $table->decimal('amount_usd', 19, 6)->default(0);
            $table->string('source_sheet', 40)->default('DB');
            $table->timestamp('source_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['period', 'category'], 'sheets_wash_cost_period_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheets_wash_cost_categories');
    }
};
