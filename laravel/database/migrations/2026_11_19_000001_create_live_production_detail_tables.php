<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_mining_breakdown', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7)->index();
            $table->string('label', 120);
            $table->string('section', 20);
            $table->string('entity', 20);
            $table->decimal('amount_usd', 19, 6);
            $table->timestamps();
            $table->unique(['period', 'label', 'section', 'entity'], 'db_mining_breakdown_source_unique');
        });

        Schema::create('db_entity_volumes', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7)->index();
            $table->string('account_code', 20);
            $table->string('activity', 120);
            $table->string('entity', 20);
            $table->decimal('qty', 19, 6)->nullable();
            $table->string('unit', 20)->nullable();
            $table->timestamps();
            $table->unique(['period', 'account_code', 'entity'], 'db_entity_volumes_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_entity_volumes');
        Schema::dropIfExists('db_mining_breakdown');
    }
};
