<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // === 1. KONFIGURASI ===
        Schema::create('system_configs', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();          // system | d365Config | sheetsConfig | inventorySetup
            $t->json('value')->nullable();
            $t->timestamps();
        });

        // Import history log (audit sederhana import GL)
        Schema::create('gl_import_batches', function (Blueprint $t) {
            $t->id();
            $t->string('filename');                    // nama file yang di-upload
            $t->string('source', 40)->default('upload'); // upload | paste | d365
            $t->unsignedInteger('row_count')->default(0);
            $t->json('months_covered')->nullable();    // ["2026-10"] — hanya bulan yg di-upsert
            $t->text('note')->nullable();
            $t->timestamp('imported_at')->nullable();
            $t->timestamps();
        });

        // === 2. MASTER (dari cost_matrix_definition.json — 163 akun) ===
        Schema::create('cost_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('account_code', 20)->unique();  // 5000000, 5000051, ...
            $t->string('account_name');
            $t->string('parent_code', 20)->nullable()->index();
            $t->boolean('is_parent')->default(false);
            $t->char('head_account', 1)->nullable();   // kepala akun: 5-9 (derivasi dari code[0])
            $t->timestamps();
        });

        // === 3. GL TRANSAKSI (dari gl_data.json — 29 kolom D365) ===
        Schema::create('gl_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id')->nullable(); // FK logic di service (tanpa constraint unutk fleksibilitas import)
            $t->string('journal_number', 40);          // "Journal number"
            $t->string('voucher', 40)->nullable();     // "Voucher"
            $t->date('entry_date')->nullable();        // "Date" (Excel serial → date)
            $t->string('year_closed', 10)->nullable(); // "Year closed"
            $t->string('account_name')->nullable();    // "AccountName"
            $t->text('description')->nullable();
            $t->string('currency', 10)->nullable()->index();
            $t->decimal('fuel_quantity', 19, 6)->default(0);       // "Fuel quantity"
            $t->decimal('amount_txn', 19, 6)->nullable();          // "Amount in transaction currency"
            $t->decimal('amount_idr', 19, 6)->nullable();          // "Amount IDR"
            $t->decimal('amount', 19, 6)->nullable();              // "Amount" (kolom H)
            $t->decimal('amount_reporting', 19, 6)->nullable()->index(); // ⭐ "Amount in reporting currency" (kolom L — sumber amount utama)
            $t->string('posting_type', 40)->nullable();
            $t->string('posting_layer', 40)->nullable();
            $t->string('vendor_account', 40)->nullable();
            $t->string('customer_account', 40)->nullable();
            $t->string('invoice_no')->nullable();
            $t->string('main_account', 20)->nullable()->index();   // "MainAccount" (kode COA)
            $t->string('activity', 20)->nullable();
            $t->string('cash_flow', 20)->nullable();
            $t->string('department', 20)->nullable();              // "Departmen" (asli di D365)
            $t->string('group_activity', 20)->nullable();          // "Group_Activity"
            $t->string('location', 20)->nullable();
            $t->string('project', 20)->nullable();
            $t->string('project_name')->nullable();
            $t->string('cost_center', 100)->nullable();             // "Cost center" (D365 values may contain descriptions)
            $t->string('valuation_to', 40)->nullable();            // "Valuation to" (Inventory/Mining/…)
            $t->string('costing_to', 40)->nullable();              // "Costing to" (rute hauling dst)
            $t->string('po_dt_number', 40)->nullable();
            $t->unsignedBigInteger('gjae_id')->nullable();
            $t->dateTime('created_datetime')->nullable();          // "Created date and time"
            // kolom sistem: period 'YYYY-MM' derivasi dari entry_date (glPeriod)
            $t->string('period', 7)->index();
            $t->timestamps();
            $t->index(['period', 'main_account']);
            $t->index(['journal_number', 'main_account', 'amount_reporting'], 'gl_dedupe_idx');
        });

        // === 4. PRODUKSI (dari production_daily_sheets.json) ===
        // 20 metric key × months{period: value}
        Schema::create('monthly_metrics', function (Blueprint $t) {
            $t->id();
            $t->string('metric_key', 40);              // coalGetting, haulingBuaWp, dst
            $t->string('label')->nullable();
            $t->string('unit', 20)->nullable();
            $t->string('period', 7)->index();
            $t->decimal('value', 19, 6)->nullable();
            $t->timestamps();
            $t->unique(['metric_key', 'period']);
        });

        // Sheet Produksi harian (kolom S = Coal Getting)
        Schema::create('daily_production', function (Blueprint $t) {
            $t->id();
            $t->date('production_date')->unique();
            $t->string('period', 7)->index();          // derivasi dari tanggal
            $t->decimal('coal_getting_mt', 19, 6)->nullable();
            $t->json('routes')->nullable();            // rute hauling harian bila ada
            $t->string('source', 20)->default('sheet'); // sheet | manual
            $t->timestamps();
            $t->index(['period', 'production_date']);
        });

        // Sheet DB costSummary — aktivitas × entitas per bulan
        Schema::create('db_cost_summary', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->index();
            $t->string('entity', 60)->nullable();
            $t->string('activity', 80)->nullable();
            $t->decimal('amount', 19, 6)->nullable();
            $t->string('currency', 10)->default('USD');
            $t->timestamps();
            $t->unique(['period', 'entity', 'activity']);
        });

        // === 5. BASELINE FINANCIAL (dari financial_baseline.json — satu objek berversion) ===
        Schema::create('financial_baselines', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('version')->default(1);
            $t->string('company');
            $t->string('currency', 10)->default('USD');
            $t->string('base_period', 7);              // 2026-09
            $t->string('gl_start_period', 7);          // 2026-10
            $t->smallInteger('year');
            $t->smallInteger('comparative_year')->nullable();
            $t->json('sections')->nullable();
            $t->json('accounts')->nullable();
            $t->json('policy')->nullable();
            $t->boolean('cash_flow_enabled')->default(false);
            $t->unique('version');
            $t->timestamps();
        });

        // === 6. INVENTORY ===
        // Opening dari inventory_model.json — 9 lokasi:
        // bua, nonWash, washed, highAsh, refeed, port, transitPort, kunz, transitKunz
        Schema::create('inventory_openings', function (Blueprint $t) {
            $t->id();
            $t->string('location', 40)->unique();      // key lokasi (bukan label)
            $t->string('location_label')->nullable();
            $t->string('period', 7);                   // startPeriod opening (2026-01)
            $t->decimal('qty_mt', 19, 6)->default(0);
            $t->decimal('value_usd', 19, 6)->default(0);
            $t->decimal('cost_per_mt', 19, 6)->nullable(); // derivasi value/qty
            $t->string('source_cells', 40)->nullable();    // provenance "F11:G11"
            $t->string('source_note')->nullable();
            $t->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->index();
            $t->string('location', 40)->index();
            $t->string('movement_type', 40);           // coalMined, transfer, outflow, adjust, addcost, dst
            $t->string('source', 60)->nullable();      // sheets | gl | dummy | manual
            $t->decimal('qty_mt', 19, 6)->default(0);
            $t->decimal('amount_usd', 19, 6)->default(0);
            $t->json('metadata')->nullable();          // muat rute/sumber detail
            $t->timestamps();
            $t->index(['period', 'location']);
        });

        // === 7. AKRUAL ===
        Schema::create('accruals', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->index();
            $t->string('account_code', 20)->index();   // COA 5-9 non-parent
            $t->string('description')->nullable();
            $t->decimal('qty', 19, 6)->default(0);
            $t->decimal('rate_usd', 19, 6)->default(0);
            $t->decimal('amount_usd', 19, 6)->default(0); // qty × rate (USD penuh)
            $t->string('source', 20)->default('manual');  // manual | db (otomatis sewa BLC/WP/Land)
            $t->string('category', 60)->nullable();       // kategori accrual otomatis
            $t->timestamps();
        });

        // Snapshot accrual otomatis saat sync (dari automatic_accrual_history.json)
        Schema::create('accrual_history', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->index();
            $t->json('payload')->nullable();           // snapshot rows otomatis
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });

        // === 8. CATATAN PENGGUNA ===
        Schema::create('cost_variance_remarks', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->index();
            $t->string('category', 60);                // ALL Cost | COGP group | Direct Expense
            $t->string('account_code', 20)->nullable();
            $t->text('remark');
            $t->timestamps();
            $t->unique(['period', 'category', 'account_code']);
        });

        Schema::create('db_cost_remarks', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->index();
            $t->string('entity_activity_key', 120);
            $t->text('remark');
            $t->timestamps();
            $t->unique(['period', 'entity_activity_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_cost_remarks');
        Schema::dropIfExists('cost_variance_remarks');
        Schema::dropIfExists('accrual_history');
        Schema::dropIfExists('accruals');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_openings');
        Schema::dropIfExists('financial_baselines');
        Schema::dropIfExists('db_cost_summary');
        Schema::dropIfExists('daily_production');
        Schema::dropIfExists('monthly_metrics');
        Schema::dropIfExists('gl_entries');
        Schema::dropIfExists('cost_accounts');
        Schema::dropIfExists('gl_import_batches');
        Schema::dropIfExists('system_configs');
    }
};
