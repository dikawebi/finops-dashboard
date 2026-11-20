<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('d365_typed_records', function (Blueprint $table): void {
            $table->string('asset', 180)->nullable()->index();
            $table->string('item_number', 180)->nullable()->index();
            $table->date('transaction_date')->nullable()->index();
            $table->decimal('cost_amount', 24, 6)->nullable();
            $table->boolean('is_posted')->nullable()->index();
            $table->dateTime('posted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('d365_typed_records', function (Blueprint $table): void {
            $table->dropIndex(['asset']);
            $table->dropIndex(['item_number']);
            $table->dropIndex(['transaction_date']);
            $table->dropIndex(['is_posted']);
            $table->dropColumn(['asset', 'item_number', 'transaction_date', 'cost_amount', 'is_posted', 'posted_at']);
        });
    }
};
