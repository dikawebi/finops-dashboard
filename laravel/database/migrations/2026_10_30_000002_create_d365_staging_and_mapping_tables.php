<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('d365_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_key', 64);
            $table->string('entity_name', 160);
            $table->string('status', 20)->default('running')->index();
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('progress_percent')->default(0);
            $table->string('max_source_key', 160)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['entity_key', 'created_at']);
        });

        Schema::create('d365_raw_records', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_key', 64);
            $table->string('entity_name', 160);
            $table->string('source_key', 255);
            $table->string('company', 32)->nullable()->index();
            $table->json('payload');
            $table->timestamps();
            $table->unique(['entity_key', 'source_key']);
            $table->index(['entity_key', 'company']);
        });

        Schema::create('d365_typed_records', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_key', 64);
            $table->string('source_key', 255);
            $table->string('company', 32)->nullable()->index();
            $table->string('account_code', 64)->nullable()->index();
            $table->string('account_name')->nullable();
            $table->string('journal_number', 80)->nullable();
            $table->string('voucher', 80)->nullable();
            $table->date('accounting_date')->nullable()->index();
            $table->string('currency', 16)->nullable();
            $table->decimal('amount_accounting', 24, 6)->nullable();
            $table->decimal('amount_reporting', 24, 6)->nullable();
            $table->decimal('amount_transaction', 24, 6)->nullable();
            $table->decimal('quantity', 24, 6)->nullable();
            $table->text('description')->nullable();
            $table->json('dimensions')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['entity_key', 'source_key']);
            $table->index(['entity_key', 'accounting_date']);
        });

        Schema::create('d365_allocation_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('account_code', 64)->nullable();
            $table->string('account_prefix', 64)->nullable();
            $table->string('activity', 120)->nullable();
            $table->string('location', 120)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('group_activity', 120)->nullable();
            $table->string('valuation_to', 120)->nullable();
            $table->string('costing_to', 120)->nullable();
            $table->string('label')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('gl_entries', function (Blueprint $table): void {
            $table->string('source_type', 32)->nullable()->index();
            $table->string('source_key', 255)->nullable();
            $table->string('d365_entity', 64)->nullable();
            $table->string('mapping_status', 32)->nullable()->index();
            $table->unique(['source_type', 'source_key'], 'gl_source_dedupe');
        });
    }

    public function down(): void
    {
        Schema::table('gl_entries', function (Blueprint $table): void {
            $table->dropUnique('gl_source_dedupe');
            $table->dropColumn(['source_type', 'source_key', 'd365_entity', 'mapping_status']);
        });
        Schema::dropIfExists('d365_allocation_mappings');
        Schema::dropIfExists('d365_typed_records');
        Schema::dropIfExists('d365_raw_records');
        Schema::dropIfExists('d365_sync_logs');
    }
};
