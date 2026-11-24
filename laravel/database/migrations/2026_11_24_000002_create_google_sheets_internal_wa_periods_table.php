<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_sheets_internal_wa_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7)->unique();
            $table->json('payload');
            $table->string('source_sheet', 80)->default('WA COGP-COGS');
            $table->timestamp('source_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheets_internal_wa_periods');
    }
};
