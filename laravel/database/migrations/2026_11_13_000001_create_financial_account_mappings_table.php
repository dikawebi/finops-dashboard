<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('account_code', 32)->unique();
            $table->string('account_name')->nullable();
            $table->string('row_id', 80);
            $table->string('source_label')->nullable();
            $table->decimal('reference_sep', 24, 6)->nullable();
            $table->decimal('reference_oct', 24, 6)->nullable();
            $table->timestamps();
            $table->index('row_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_mappings');
    }
};
