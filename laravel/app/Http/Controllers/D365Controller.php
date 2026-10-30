<?php

namespace App\Http\Controllers;

use App\Jobs\RunD365Sync;
use App\Models\GlImportBatch;
use App\Services\D365\D365AllocationService;
use App\Services\D365\D365SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class D365Controller extends Controller
{
    public function __construct(private readonly D365SyncService $sync, private readonly D365AllocationService $allocation) {}

    public function entities(): array { return ['entities' => $this->sync->latestStatus()]; }

    public function sync(Request $request): array
    {
        $data = $request->validate(['entity' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $entity = $data['entity'] ?? 'all';
        abort_unless($entity === 'all' || $this->sync->entityByKey($entity), 422, 'Entity tidak dikenal.');
        RunD365Sync::dispatch($entity, $data['date_from'] ?? null, $data['date_to'] ?? null);
        return ['queued' => true, 'entity' => $entity];
    }

    public function progress(): array
    {
        return ['entities' => collect($this->sync->latestStatus())->map(function (array $item): array {
            $last = $item['last'];
            return ['key' => $item['key'], 'label' => $item['label'], 'status' => $last?->status ?? 'pending', 'fetched' => (int) ($last?->fetched ?? 0), 'progress' => (int) ($last?->progress_percent ?? 0), 'message' => $last?->message, 'staging_count' => $item['staging_count'], 'updated_at' => $last?->updated_at];
        })->values()];
    }

    public function staging(Request $request): array
    {
        $entity = (string) $request->query('entity', 'gl_entries');
        abort_unless($this->sync->entityByKey($entity), 422, 'Entity tidak dikenal.');
        $query = DB::table('d365_typed_records')->where('entity_key', $entity)->orderByDesc('accounting_date')->orderBy('source_key');
        if ($request->filled('date_from')) $query->whereDate('accounting_date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('accounting_date', '<=', $request->date_to);
        $rows = $query->paginate(50)->withQueryString();
        return ['entity' => $entity, 'rows' => $rows];
    }

    public function preview(Request $request): array
    {
        $data = $request->validate(['entity' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        return $this->allocation->preview($data['entity'] ?? 'gl_entries', $data['date_from'] ?? null, $data['date_to'] ?? null);
    }

    public function import(Request $request): array
    {
        $data = $request->validate(['entity' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $entity = $data['entity'] ?? 'gl_entries';
        abort_unless($entity === 'gl_entries', 422, 'Hanya GL staging yang dapat diimport ke dashboard utama.');
        $query = DB::table('d365_typed_records')->where('entity_key', $entity);
        if (!empty($data['date_from'])) $query->whereDate('accounting_date', '>=', $data['date_from']);
        if (!empty($data['date_to'])) $query->whereDate('accounting_date', '<=', $data['date_to']);
        $rows = $query->get();
        $batch = GlImportBatch::create(['filename' => 'd365-staging-import.json', 'source' => 'd365', 'row_count' => $rows->count(), 'months_covered' => $rows->pluck('accounting_date')->filter()->map(fn ($date): string => substr((string) $date, 0, 7))->unique()->values()->all(), 'note' => 'Explicit import from D365 staging', 'imported_at' => now()]);
        $mapped = 0; $unmapped = 0;
        foreach ($rows->chunk(200) as $chunk) {
            $entries = $chunk->map(function ($row) use (&$mapped, &$unmapped, $batch): array {
                $dimensions = json_decode((string) $row->dimensions, true) ?: [];
                $allocation = $this->allocation->resolve(['account_code' => $row->account_code, 'dimensions' => $dimensions]);
                $allocation['mapping_status'] === 'mapped' ? $mapped++ : $unmapped++;
                $amount = $row->amount_accounting ?? $row->amount_reporting ?? 0;
                return ['batch_id' => $batch->id, 'journal_number' => $row->journal_number ?: $row->source_key, 'voucher' => $row->voucher, 'entry_date' => $row->accounting_date, 'year_closed' => 'No', 'account_name' => $row->account_name, 'description' => $row->description, 'currency' => $row->currency, 'fuel_quantity' => $row->quantity ?? 0, 'amount_txn' => $row->amount_transaction, 'amount_idr' => $amount, 'amount' => $row->amount_accounting, 'amount_reporting' => $row->amount_reporting ?? $amount, 'posting_type' => null, 'posting_layer' => null, 'vendor_account' => null, 'customer_account' => null, 'invoice_no' => null, 'main_account' => $row->account_code, 'activity' => $dimensions['ACTIVITY'] ?? null, 'cash_flow' => $dimensions['CASH_FLOW'] ?? null, 'department' => $dimensions['DEPARTMENT'] ?? null, 'group_activity' => $dimensions['GROUP_ACTIVITY'] ?? null, 'location' => $dimensions['LOCATION'] ?? null, 'project' => $dimensions['PROJECT'] ?? null, 'project_name' => null, 'cost_center' => $dimensions['COST_CENTER'] ?? null, 'valuation_to' => $allocation['valuation_to'], 'costing_to' => $allocation['costing_to'], 'po_dt_number' => null, 'gjae_id' => null, 'created_datetime' => null, 'period' => $row->accounting_date ? substr((string) $row->accounting_date, 0, 7) : 'unknown', 'source_type' => 'd365', 'source_key' => $row->source_key, 'd365_entity' => 'gl_entries', 'mapping_status' => $allocation['mapping_status'], 'created_at' => now(), 'updated_at' => now()];
            })->all();
            DB::table('gl_entries')->upsert($entries, ['source_type', 'source_key'], array_values(array_diff(array_keys($entries[0] ?? []), ['id', 'source_type', 'source_key', 'created_at'])));
        }
        return ['batch' => $batch, 'rows' => $rows->count(), 'mapped' => $mapped, 'unmapped' => $unmapped];
    }

    public function mappings(): array { return ['rows' => DB::table('d365_allocation_mappings')->orderByDesc('priority')->orderBy('id')->get()]; }

    public function storeMapping(Request $request): array
    {
        $data = $request->validate($this->mappingRules());
        $id = DB::table('d365_allocation_mappings')->insertGetId($data + ['is_active' => $request->boolean('is_active', true), 'created_at' => now(), 'updated_at' => now()]);
        return ['id' => $id];
    }

    public function updateMapping(Request $request, int $id): array { $data = $request->validate($this->mappingRules()); DB::table('d365_allocation_mappings')->where('id', $id)->update($data + ['is_active' => $request->boolean('is_active'), 'updated_at' => now()]); return ['updated' => true]; }
    public function deleteMapping(int $id): array { DB::table('d365_allocation_mappings')->where('id', $id)->delete(); return ['deleted' => true]; }

    private function mappingRules(): array
    {
        return [
            'account_code' => ['nullable', 'string', 'max:64'], 'account_prefix' => ['nullable', 'string', 'max:64'],
            'group_activity_code' => ['nullable', 'string', 'max:64'], 'group_activity_name' => ['nullable', 'string', 'max:160'],
            'activity_code' => ['nullable', 'string', 'max:64'], 'activity_name' => ['nullable', 'string', 'max:160'],
            'location_code' => ['nullable', 'string', 'max:64'], 'location_name' => ['nullable', 'string', 'max:160'],
            'activity' => ['nullable', 'string', 'max:120'], 'location' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'], 'group_activity' => ['nullable', 'string', 'max:120'],
            'valuation_to' => ['required', 'string', 'max:120'], 'costing_to' => ['required', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:255'], 'priority' => ['required', 'integer', 'min:0', 'max:10000'], 'is_active' => ['nullable', 'boolean'],
        ];
    }
}
