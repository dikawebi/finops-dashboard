<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 32)->default('viewer')->index();
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->index();
            }
        });
    }

    public function down(): void
    {
        // These fields may predate the migration in an existing installation.
        // Keep them during rollback to avoid deleting established access data.
    }
};
