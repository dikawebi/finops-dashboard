<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_daily_cogp_parameters', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7);
            $table->string('section', 20);
            $table->string('label', 120);
            $table->string('entity', 80)->default('');
            $table->decimal('value', 19, 9)->nullable();
            $table->string('unit', 40)->nullable();
            $table->timestamps();
            $table->unique(['period', 'section', 'label', 'entity'], 'db_daily_cogp_params_unique');
            $table->index(['period', 'section'], 'db_daily_cogp_params_period_section_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_daily_cogp_parameters');
    }
};
