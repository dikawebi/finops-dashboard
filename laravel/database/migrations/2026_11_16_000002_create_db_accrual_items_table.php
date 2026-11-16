<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_accrual_items', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7)->index();
            $table->string('account_code', 20)->index();
            $table->string('name');
            $table->string('entity', 80);
            $table->string('category', 80);
            $table->decimal('amount_usd', 19, 6)->default(0);
            $table->decimal('qty', 19, 6)->nullable();
            $table->decimal('rate_usd', 19, 9)->nullable();
            $table->string('unit', 20)->nullable();
            $table->timestamps();
            $table->unique(['period', 'account_code', 'entity', 'category'], 'db_accrual_period_account_entity_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_accrual_items');
    }
};
