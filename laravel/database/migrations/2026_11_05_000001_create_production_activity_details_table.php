<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('production_activity_details')) Schema::create('production_activity_details', function (Blueprint $table): void {
            $table->id();
            $table->date('production_date');
            $table->string('activity', 80);
            $table->string('location', 80)->nullable();
            $table->string('activity_type', 80)->nullable();
            $table->string('route', 100)->nullable();
            $table->string('contractor', 100)->nullable();
            $table->string('equipment', 100)->nullable();
            $table->string('metric', 60);
            $table->decimal('value', 19, 6)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('source_key', 120)->unique();
            $table->string('source', 30)->default('google_sheets');
            $table->timestamps();
            $table->index(['production_date', 'activity', 'location'], 'prod_activity_date_activity_loc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_activity_details');
    }
};
