<?php

namespace App\Http\Controllers;

use App\Jobs\RunD365Sync;
use App\Models\GlImportBatch;
use App\Services\D365\D365AllocationService;
use App\Services\D365\D365SyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

class D365Controller extends Controller
{
    public function __construct(private readonly D365SyncService $sync, private readonly D365AllocationService $allocation) {}

    public function entities(): array { return ['entities' => $this->sync->latestStatus()]; }

    public function sync(Request $request): array
    {
        $data = $request->validate(['entity' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $entity = $data['entity'] ?? 'all';
        abort_unless($entity === 'all' || $this->sync->entityByKey($entity), 422, 'Entity tidak dikenal.');
        $this->sync->clearStop();
        $this->sync->ensureQueueWorker();
        RunD365Sync::dispatch($entity, $data['date_from'] ?? null, $data['date_to'] ?? null);
        return ['queued' => true, 'entity' => $entity];
    }

    public function stopSync(): array
    {
        $this->sync->requestStop();
        $this->sync->clearTransactionStaging();
        DB::table('d365_sync_logs')->where('status', 'running')->update(['status' => 'stopped', 'message' => 'Sync dihentikan oleh user.', 'updated_at' => now()]);
        return ['stopped' => true];
    }

    public function progress(): array
    {
        return ['entities' => collect($this->sync->latestStatus())->map(function (array $item): array {
            $last = $item['last'];
            return ['key' => $item['key'], 'label' => $item['label'], 'status' => $last?->status ?? 'pending', 'fetched' => (int) ($last?->fetched ?? 0), 'inserted' => (int) ($last?->inserted ?? 0), 'updated' => (int) ($last?->updated ?? 0), 'progress' => (int) ($last?->progress_percent ?? 0), 'message' => $last?->message, 'staging_count' => $item['staging_count'], 'updated_at' => $last?->updated_at];
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
        $rows = $this->allocation->stagingRows($entity, $data['date_from'] ?? null, $data['date_to'] ?? null);
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

    public function importMappings(Request $request): array|JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xls,xlsx,csv,tsv,txt']]);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        try {
            if (in_array($extension, ['csv', 'tsv', 'txt'], true)) {
                $reader = new Csv();
                $reader->setReadDataOnly(true)->setDelimiter($extension === 'csv' ? ',' : "\t");
            } else {
                $reader = IOFactory::createReaderForFile($file->getRealPath());
                $reader->setReadDataOnly(true);
            }
            $sheet = $reader->load($file->getRealPath())->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'File mapping tidak dapat dibaca: '.$exception->getMessage()], 422);
        }

        $imported = 0; $skipped = 0; $errors = [];
        foreach (array_slice($rows, 1) as $index => $row) {
            $values = array_map(static fn ($value): string => trim((string) ($value ?? '')), array_values($row));
            if (count($values) < 8 || count(array_filter($values, static fn (string $value): bool => $value !== '')) === 0) continue;
            [$groupCode, $groupName, $activityCode, $activityName, $locationCode, $locationName, $valuationTo, $costingTo] = array_pad(array_slice($values, 0, 8), 8, '');
            if ($groupCode === '' || $activityCode === '' || $locationCode === '' || $valuationTo === '' || $costingTo === '') {
                $skipped++;
                if (count($errors) < 10) $errors[] = 'Baris '.($index + 2).' harus memiliki 8 kolom mapping wajib.';
                continue;
            }
            DB::table('d365_allocation_mappings')->updateOrInsert([
                'group_activity_code' => $groupCode, 'activity_code' => $activityCode, 'location_code' => $locationCode,
                'valuation_to' => $valuationTo, 'costing_to' => $costingTo,
            ], [
                'group_activity_name' => $groupName, 'activity_name' => $activityName, 'location_name' => $locationName,
                'label' => $groupName.' / '.$activityName.' / '.$locationName, 'priority' => 100, 'is_active' => true,
                'updated_at' => now(), 'created_at' => now(),
            ]);
            $imported++;
        }
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'total_rows' => max(0, count($rows) - 1)];
    }

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
