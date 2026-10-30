<?php

namespace App\Services\D365;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class D365SyncService
{
    public const ENTITIES = [
        ['key' => 'gl_entries', 'label' => 'GL Account Entries', 'entity' => 'GeneralJournalAccountEntryBiEntities', 'mode' => 'incremental', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => null],
        ['key' => 'gl_journal_entries', 'label' => 'GL Journal Entries', 'entity' => 'GeneralJournalEntryBiEntities', 'mode' => 'incremental', 'source_field' => 'SourceKey', 'date_field' => 'AccountingDate', 'company_field' => 'SubledgerVoucherDataAreaId'],
        ['key' => 'main_accounts', 'label' => 'Main Accounts', 'entity' => 'MainAccountBiEntities', 'mode' => 'snapshot', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => null],
        ['key' => 'dimension_values', 'label' => 'Financial Dimension Values', 'entity' => 'FinancialDimensionValues', 'mode' => 'snapshot', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => 'LegalEntityId', 'exclude_company' => 'Arcs'],
        ['key' => 'ledger_journal_lines', 'label' => 'Ledger Journal Lines', 'entity' => 'LedgerJournalTransBiEntities', 'mode' => 'incremental', 'source_field' => 'SourceKey', 'date_field' => 'DocumentDate', 'company_field' => 'dataAreaId'],
        ['key' => 'ledger_entry_journals', 'label' => 'Ledger Entry Journals', 'entity' => 'LedgerEntryJournalBiEntities', 'mode' => 'incremental', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => null],
        ['key' => 'vendors', 'label' => 'Vendors', 'entity' => 'VendorsV2', 'mode' => 'snapshot', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => 'dataAreaId'],
        ['key' => 'customers', 'label' => 'Customers', 'entity' => 'CustomersV3', 'mode' => 'snapshot', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => 'dataAreaId'],
        ['key' => 'inventory_trans', 'label' => 'Inventory Transactions', 'entity' => 'InventTransBiEntities', 'mode' => 'incremental', 'source_field' => 'SourceKey', 'date_field' => 'DateFinancial', 'company_field' => 'dataAreaId'],
    ];

    public function client(): D365ODataClient { return new D365ODataClient(config('services.d365', [])); }
    public function entityByKey(string $key): ?array { return collect(self::ENTITIES)->firstWhere('key', $key); }
    public function transactionEntityKeys(): array { return ['gl_entries', 'gl_journal_entries', 'ledger_journal_lines', 'ledger_entry_journals', 'inventory_trans']; }
    public function clearTransactionStaging(): void
    {
        $entities = $this->transactionEntityKeys();
        DB::transaction(function () use ($entities): void {
            DB::table('d365_raw_records')->whereIn('entity_key', $entities)->delete();
            DB::table('d365_typed_records')->whereIn('entity_key', $entities)->delete();
            DB::table('gl_entries')->where('source_type', 'd365')->delete();
            DB::table('jobs')->where('payload', 'like', '%RunD365Sync%')->delete();
        });
    }
    public function ensureQueueWorker(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! app()->environment('local')) return;
        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $command = 'start "" /B '.$php.' '.$artisan.' queue:work database --queue=default --tries=1 --timeout=900 --stop-when-empty';
        pclose(popen($command, 'r'));
    }

    public function syncEntity(array $entity, ?int $limit = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $logId = DB::table('d365_sync_logs')->insertGetId(['entity_key' => $entity['key'], 'entity_name' => $entity['entity'], 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        $fetched = $inserted = $updated = $pages = 0;
        $maxSourceKey = null;
        try {
            if ($this->stopRequested()) throw new D365Exception('Sync dihentikan oleh user.');
            if ($entity['mode'] === 'snapshot') {
                DB::table('d365_raw_records')->where('entity_key', $entity['key'])->delete();
                DB::table('d365_typed_records')->where('entity_key', $entity['key'])->delete();
            }
            $client = $this->client();
            foreach ($this->filtersForEntity($entity, $dateFrom, $dateTo) as $filter) {
                foreach ($client->streamEntity($entity['entity'], null, $filter) as $source) {
                    if ($this->stopRequested()) throw new D365Exception('Sync dihentikan oleh user.');
                    if ($limit !== null && $fetched >= $limit) break 2;
                    $fetched++;
                    $sourceKey = $this->sourceKey($source);
                    if ($entity['mode'] === 'incremental' && ctype_digit($sourceKey) && ($maxSourceKey === null || (int) $sourceKey > (int) $maxSourceKey)) $maxSourceKey = $sourceKey;
                    $raw = ['entity_key' => $entity['key'], 'entity_name' => $entity['entity'], 'source_key' => $sourceKey, 'company' => $this->company($source, $entity), 'payload' => json_encode($source), 'created_at' => now(), 'updated_at' => now()];
                    $existing = DB::table('d365_raw_records')->where(['entity_key' => $entity['key'], 'source_key' => $sourceKey])->exists();
                    DB::table('d365_raw_records')->upsert([$raw], ['entity_key', 'source_key'], ['entity_name', 'company', 'payload', 'updated_at']);
                    $existing ? $updated++ : $inserted++;
                    $typed = $this->typed($entity, $source, $sourceKey);
                    DB::table('d365_typed_records')->upsert([$typed], ['entity_key', 'source_key'], array_keys(array_diff_key($typed, ['entity_key' => true, 'source_key' => true, 'created_at' => true])));
                    if ($fetched % 25 === 0) {
                        DB::table('d365_sync_logs')->where('id', $logId)->update(['fetched' => $fetched, 'inserted' => $inserted, 'updated' => $updated, 'pages' => (int) ceil($fetched / 1000), 'max_source_key' => $maxSourceKey, 'updated_at' => now()]);
                    }
                }
            }
            if ($this->stopRequested()) throw new D365Exception('Sync dihentikan oleh user.');
            $pages = (int) ceil($fetched / 1000);
            DB::table('d365_sync_logs')->where('id', $logId)->update(['status' => 'success', 'fetched' => $fetched, 'inserted' => $inserted, 'updated' => $updated, 'pages' => $pages, 'progress_percent' => 100, 'max_source_key' => $maxSourceKey, 'updated_at' => now()]);
            return ['status' => 'success', 'entity' => $entity, 'fetched' => $fetched, 'inserted' => $inserted, 'updated' => $updated];
        } catch (\Throwable $exception) {
            $status = $this->stopRequested() ? 'stopped' : 'failed';
            DB::table('d365_sync_logs')->where('id', $logId)->update(['status' => $status, 'fetched' => $fetched, 'message' => $exception->getMessage(), 'updated_at' => now()]);
            return ['status' => $status, 'entity' => $entity, 'fetched' => $fetched, 'error' => $exception->getMessage()];
        }
    }

    public function syncAll(?int $limit = null, ?string $dateFrom = null, ?string $dateTo = null): array { $order = ['main_accounts', 'dimension_values', 'vendors', 'customers', 'gl_journal_entries', 'gl_entries', 'ledger_journal_lines', 'ledger_entry_journals', 'inventory_trans']; $results = []; foreach ($order as $key) { if ($this->stopRequested()) break; $results[] = $this->syncEntity($this->entityByKey($key), $limit, $dateFrom, $dateTo); } return $results; }
    public function requestStop(): void { Cache::put('d365_sync_stop', true, now()->addHour()); }
    public function clearStop(): void { Cache::forget('d365_sync_stop'); }
    public function stopRequested(): bool { return (bool) Cache::get('d365_sync_stop', false); }
    public function latestStatus(): array { return collect(self::ENTITIES)->map(function (array $entity): array { $log = DB::table('d365_sync_logs')->where('entity_key', $entity['key'])->latest('id')->first(); return $entity + ['last' => $log, 'staging_count' => DB::table('d365_raw_records')->where('entity_key', $entity['key'])->count(), 'typed_count' => DB::table('d365_typed_records')->where('entity_key', $entity['key'])->count()]; })->all(); }

    private function filter(array $entity, ?string $dateFrom, ?string $dateTo): ?string
    {
        $parts = [];
        $companyField = $entity['company_field'];
        if ($companyField && filled($entity['exclude_company'] ?? null)) $parts[] = $companyField." ne '".str_replace("'", "''", $entity['exclude_company'])."'";
        elseif ($companyField && filled(config('services.d365.company'))) $parts[] = $companyField." eq '".str_replace("'", "''", config('services.d365.company'))."'";
        if ($entity['mode'] === 'incremental' && ($entity['source_field'] ?? null)) {
            $cursor = DB::table('d365_sync_logs')->where('entity_key', $entity['key'])->where('status', 'success')->whereNotNull('max_source_key')->latest('id')->value('max_source_key');
            if ($cursor !== null && ctype_digit((string) $cursor)) $parts[] = $entity['source_field'].' gt '.(int) $cursor;
        }
        if ($entity['date_field'] && $dateFrom) $parts[] = $entity['date_field']." ge ".date('Y-m-d', strtotime($dateFrom));
        if ($entity['date_field'] && $dateTo) $parts[] = $entity['date_field']." le ".date('Y-m-d', strtotime($dateTo));
        return $parts ? implode(' and ', $parts) : null;
    }
    private function filtersForEntity(array $entity, ?string $dateFrom, ?string $dateTo): array
    {
        if (!$dateFrom && !$dateTo) return [$this->filter($entity, null, null)];
        if ($entity['key'] === 'gl_entries') {
            $keys = DB::table('d365_typed_records')->where('entity_key', 'gl_journal_entries')->when($dateFrom, fn ($query) => $query->whereDate('accounting_date', '>=', $dateFrom))->when($dateTo, fn ($query) => $query->whereDate('accounting_date', '<=', $dateTo))->pluck('source_key')->filter()->map(fn ($key): string => (string) $key)->unique()->values();
            return $keys->chunk(50)->map(fn ($chunk): string => $this->appendFilter($this->filter($entity, null, null), $chunk->map(fn (string $key): string => 'GeneralJournalEntry eq '.$key)->implode(' or ')))->all();
        }
        if ($entity['key'] === 'ledger_entry_journals') {
            $keys = DB::table('d365_typed_records')->where('entity_key', 'ledger_journal_lines')->when($dateFrom, fn ($query) => $query->whereDate('accounting_date', '>=', $dateFrom))->when($dateTo, fn ($query) => $query->whereDate('accounting_date', '<=', $dateTo))->pluck('journal_number')->filter()->map(fn ($key): string => (string) $key)->unique()->values();
            return $keys->chunk(50)->map(fn ($chunk): string => $this->appendFilter($this->filter($entity, null, null), $chunk->map(fn (string $key): string => "JournalNumber eq '".str_replace("'", "''", $key)."'")->implode(' or ')))->all();
        }
        return [$this->filter($entity, $dateFrom, $dateTo)];
    }
    private function appendFilter(?string $base, string $parent): string { return $base ? $base.' and ('.$parent.')' : '('.$parent.')'; }
    private function sourceKey(array $row): string { return (string) ($row['SourceKey'] ?? $row['sourceKey'] ?? sha1(json_encode($row))); }
    private function company(array $row, array $entity): ?string { $field = $entity['company_field']; return $field ? (string) ($row[$field] ?? '') : null; }
    private function typed(array $entity, array $row, string $sourceKey): array
    {
        $dimensions = $row['LedgerDimensionValuesJson'] ?? $row['Dimensions'] ?? [];
        if (is_string($dimensions)) $dimensions = json_decode($dimensions, true) ?: [];
        if (isset($dimensions[0]) && is_array($dimensions[0])) $dimensions = $dimensions[0];
        return ['entity_key' => $entity['key'], 'source_key' => $sourceKey, 'company' => $this->company($row, $entity), 'account_code' => (string) ($row['MainAccount'] ?? $row['MainAccountId'] ?? $row['LedgerAccount'] ?? ''), 'account_name' => (string) ($row['Name'] ?? ''), 'journal_number' => (string) ($row['JournalNumber'] ?? $row['JournalNum'] ?? ''), 'voucher' => (string) ($row['SubledgerVoucher'] ?? $row['Voucher'] ?? ''), 'accounting_date' => $this->date($row['AccountingDate'] ?? $row['DateFinancial'] ?? $row['DocumentDate'] ?? $row['TransDate'] ?? null), 'currency' => (string) ($row['TransactionCurrencyCode'] ?? $row['CurrencyCode'] ?? ''), 'amount_accounting' => $row['AccountingCurrencyAmount'] ?? $row['AmountCurDebit'] ?? null, 'amount_reporting' => $row['ReportingCurrencyAmount'] ?? null, 'amount_transaction' => $row['TransactionCurrencyAmount'] ?? null, 'quantity' => $row['Quantity'] ?? $row['Qty'] ?? null, 'description' => (string) ($row['Text'] ?? $row['Txt'] ?? ''), 'dimensions' => json_encode($dimensions), 'payload' => json_encode($row), 'created_at' => now(), 'updated_at' => now()];
    }
    private function date(mixed $value): ?string { if (!$value) return null; try { return date('Y-m-d', strtotime((string) $value)); } catch (\Throwable) { return null; } }
}
