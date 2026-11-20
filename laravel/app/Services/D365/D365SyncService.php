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
        ['key' => 'mapping_valuation_by_findims', 'label' => 'Mapping Valuation by Findim', 'entity' => 'MappingValuationByFindims', 'mode' => 'replace', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => 'dataAreaId', 'company_value' => 'bp'],
        ['key' => 'ledger_journal_lines', 'label' => 'Ledger Journal Lines', 'entity' => 'LedgerJournalTransBiEntities', 'mode' => 'incremental', 'source_field' => 'SourceKey', 'date_field' => 'DocumentDate', 'company_field' => 'dataAreaId'],
        ['key' => 'ledger_entry_journals', 'label' => 'Ledger Entry Journals', 'entity' => 'LedgerEntryJournalBiEntities', 'mode' => 'incremental', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => null],
        ['key' => 'vendors', 'label' => 'Vendors', 'entity' => 'VendorsV2', 'mode' => 'snapshot', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => 'dataAreaId'],
        ['key' => 'customers', 'label' => 'Customers', 'entity' => 'CustomersV3', 'mode' => 'snapshot', 'source_field' => 'SourceKey', 'date_field' => null, 'company_field' => 'dataAreaId'],
        ['key' => 'inventory_movement_entries', 'label' => 'Inventory Movement Journal Entries (Posted)', 'entity' => 'InventoryMovementJournalEntriesV4', 'mode' => 'period_replace', 'source_field' => null, 'date_field' => 'TransactionDate', 'company_field' => 'dataAreaId', 'company_value' => 'bp'],
    ];

    public function client(): D365ODataClient { return new D365ODataClient(config('services.d365', [])); }
    public function entityByKey(string $key): ?array { return collect(self::ENTITIES)->firstWhere('key', $key); }
    public function transactionEntityKeys(): array { return ['gl_entries', 'gl_journal_entries', 'ledger_journal_lines', 'ledger_entry_journals', 'inventory_movement_entries']; }
    public function resetTransactionData(): void
    {
        $entities = $this->transactionEntityKeys();
        DB::transaction(function () use ($entities): void {
            DB::table('d365_raw_records')->whereIn('entity_key', $entities)->delete();
            DB::table('d365_typed_records')->whereIn('entity_key', $entities)->delete();
            DB::table('gl_entries')->where('source_type', 'd365')->delete();
        });
    }
    public function clearTransactionStaging(): void
    {
        $this->resetTransactionData();
        DB::transaction(function (): void {
            DB::table('jobs')->where('payload', 'like', '%RunD365Sync%')->delete();
        });
    }
    public function ensureQueueWorker(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! app()->environment('local')) return;
        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $command = 'start "" /B '.$php.' -d memory_limit=512M '.$artisan.' queue:work database --queue=default --tries=1 --timeout=1800 --stop-when-empty';
        pclose(popen($command, 'r'));
    }

    public function syncEntity(array $entity, ?int $limit = null, ?string $dateFrom = null, ?string $dateTo = null, bool $forceFull = false): array
    {
        if ($entity['key'] === 'inventory_movement_entries') {
            return $this->syncPostedInventoryMovementEntries($entity, $dateFrom, $dateTo);
        }

        $logId = DB::table('d365_sync_logs')->insertGetId(['entity_key' => $entity['key'], 'entity_name' => $entity['entity'], 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        $fetched = $inserted = $updated = $pages = 0;
        $maxSourceKey = null;
        try {
            if ($this->stopRequested()) throw new D365Exception('Sync dihentikan oleh user.');
            if (in_array($entity['mode'], ['snapshot', 'replace'], true)) {
                DB::table('d365_raw_records')->where('entity_key', $entity['key'])->delete();
                DB::table('d365_typed_records')->where('entity_key', $entity['key'])->delete();
            }
            $client = $this->client();
            foreach ($this->filtersForEntity($entity, $dateFrom, $dateTo, $forceFull) as $filter) {
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
                        DB::table('d365_sync_logs')->where('id', $logId)->update(['fetched' => $fetched, 'inserted' => $inserted, 'updated' => $updated, 'pages' => (int) ceil($fetched / 1000), 'progress_percent' => min(99, max(1, 1 + intdiv($fetched, 100))), 'max_source_key' => $maxSourceKey, 'updated_at' => now()]);
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

    private function syncPostedInventoryMovementEntries(array $entity, ?string $dateFrom, ?string $dateTo): array
    {
        $logId = DB::table('d365_sync_logs')->insertGetId(['entity_key' => $entity['key'], 'entity_name' => $entity['entity'], 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        $fetched = 0;
        try {
            if (! $dateFrom || ! $dateTo) throw new D365Exception('Pilih periode tanggal untuk Inventory Movement Journal Entries.');
            if ($this->stopRequested()) throw new D365Exception('Sync dihentikan oleh user.');

            $from = (new \DateTimeImmutable($dateFrom))->format('Y-m-d');
            $toExclusive = (new \DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d');
            $company = (string) ($entity['company_value'] ?? config('services.d365.company', 'bp'));
            $quote = static fn (string $value): string => "'".str_replace("'", "''", $value)."'";
            $transactionFilter = 'dataAreaId eq '.$quote($company).' and TransactionDate ge '.$from.' and TransactionDate lt '.$toExclusive;
            $client = $this->client();
            $lines = [];
            foreach ($client->streamEntity($entity['entity'], null, $transactionFilter) as $row) {
                if ($this->stopRequested()) throw new D365Exception('Sync dihentikan oleh user.');
                $fetched++;
                $lines[] = $row;
                if ($fetched % 100 === 0) {
                    DB::table('d365_sync_logs')->where('id', $logId)->update(['fetched' => $fetched, 'progress_percent' => min(70, intdiv($fetched, 50)), 'message' => 'Mengambil transaksi berdasarkan TransactionDate…', 'updated_at' => now()]);
                }
            }

            $journalNumbers = collect($lines)->pluck('JournalNumber')->filter(fn ($value): bool => filled($value))->map(fn ($value): string => (string) $value)->unique()->values();
            $postedHeaders = [];
            foreach ($journalNumbers->chunk(25) as $batch) {
                if ($this->stopRequested()) throw new D365Exception('Sync dihentikan oleh user.');
                $journalFilter = $batch->map(fn (string $number): string => 'JournalNumber eq '.$quote($number))->implode(' or ');
                $headerFilter = 'dataAreaId eq '.$quote($company)
                    .' and IsPosted eq Microsoft.Dynamics.DataEntities.NoYes'.$quote('Yes')
                    .' and ('.$journalFilter.')';
                foreach ($client->streamEntity('InventoryMovementJournalHeaders', null, $headerFilter) as $header) {
                    $key = (string) ($header['dataAreaId'] ?? '').'|'.(string) ($header['JournalNumber'] ?? '');
                    if ($key !== '|') $postedHeaders[$key] = $header;
                }
            }

            $now = now();
            $typedRows = [];
            foreach ($lines as $line) {
                $companyCode = (string) ($line['dataAreaId'] ?? '');
                $journalNumber = (string) ($line['JournalNumber'] ?? '');
                $headerKey = $companyCode.'|'.$journalNumber;
                if (! isset($postedHeaders[$headerKey])) continue;
                $header = $postedHeaders[$headerKey];
                $lineNumber = (string) ($line['LineNumber'] ?? '');
                if ($companyCode === '' || $journalNumber === '' || $lineNumber === '') {
                    throw new D365Exception('Transaksi posted tidak memiliki company, JournalNumber, atau LineNumber; staging periode dibatalkan.');
                }

                $sourceKey = hash('sha256', json_encode([$companyCode, $journalNumber, $lineNumber], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                if (isset($typedRows[$sourceKey])) {
                    throw new D365Exception("Duplicate company/journal/line key for {$journalNumber}, line {$lineNumber}; staging periode dibatalkan.");
                }
                $transactionDate = $this->date($line['TransactionDate'] ?? null);
                if (! $transactionDate) throw new D365Exception("Tanggal transaksi tidak valid pada jurnal {$journalNumber}, line {$lineNumber}.");
                $postedAt = $this->dateTime($header['PostedDateTime'] ?? null);
                $payload = $line;
                $payload['IsPosted'] = $header['IsPosted'] ?? 'Yes';
                $payload['HeaderDescription'] = $header['Description'] ?? '';
                $payload['PostedDateTime'] = $header['PostedDateTime'] ?? null;
                $dimensions = [
                    'defaultLedgerDimensionDisplayValue' => $line['DefaultLedgerDimensionDisplayValue'] ?? '',
                    'offsetMainAccountIdDisplayValue' => $line['OffsetMainAccountIdDisplayValue'] ?? '',
                ];
                $typedRows[$sourceKey] = [
                    'entity_key' => $entity['key'], 'source_key' => $sourceKey, 'company' => $companyCode,
                    'asset' => filled($line['WBPDTNumber'] ?? null) ? trim((string) $line['WBPDTNumber']) : null,
                    'item_number' => filled($line['ItemNumber'] ?? null) ? (string) $line['ItemNumber'] : null,
                    'account_code' => null, 'account_name' => null, 'journal_number' => $journalNumber,
                    'voucher' => (string) ($line['Voucher'] ?? ''), 'accounting_date' => $transactionDate, 'transaction_date' => $transactionDate,
                    'currency' => (string) ($line['CurrencyCode'] ?? ''), 'amount_accounting' => null,
                    'amount_reporting' => null, 'amount_transaction' => null,
                    'cost_amount' => is_numeric($line['CostAmount'] ?? null) ? (float) $line['CostAmount'] : null,
                    'quantity' => is_numeric($line['InventoryQuantity'] ?? null) ? (float) $line['InventoryQuantity'] : null,
                    'is_posted' => true, 'posted_at' => $postedAt,
                    'description' => (string) ($header['Description'] ?? ''),
                    'dimensions' => json_encode($dimensions, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            $sourceKeys = array_keys($typedRows);
            $existingPeriodKeys = DB::table('d365_typed_records')->where('entity_key', $entity['key'])
                ->where('company', $company)->whereBetween('transaction_date', [$from, $dateTo])
                ->pluck('source_key')->map(fn ($value): string => (string) $value)->all();
            $existingMatchingKeys = $sourceKeys
                ? DB::table('d365_typed_records')->where('entity_key', $entity['key'])->whereIn('source_key', $sourceKeys)
                    ->pluck('source_key')->map(fn ($value): string => (string) $value)->all()
                : [];
            $existingKeys = array_values(array_unique(array_merge($existingPeriodKeys, $existingMatchingKeys)));
            $existingSet = array_fill_keys($existingMatchingKeys, true);
            $updated = count(array_filter($sourceKeys, fn (string $key): bool => isset($existingSet[$key])));
            $inserted = count($sourceKeys) - $updated;
            $rawRows = [];
            foreach ($typedRows as $key => $typed) {
                $rawRows[] = ['entity_key' => $entity['key'], 'entity_name' => $entity['entity'], 'source_key' => $key, 'company' => $typed['company'], 'payload' => $typed['payload'], 'created_at' => $now, 'updated_at' => $now];
            }

            DB::table('d365_sync_logs')->where('id', $logId)->update(['fetched' => $fetched, 'inserted' => $inserted, 'updated' => $updated, 'progress_percent' => 95, 'message' => sprintf('Header lookup selesai: %d jurnal posted; menulis %d transaksi ke staging…', count($postedHeaders), count($typedRows)), 'updated_at' => $now]);

            DB::transaction(function () use ($entity, $company, $from, $dateTo, $existingKeys, $typedRows, $rawRows): void {
                if ($existingKeys) {
                    DB::table('d365_typed_records')->where('entity_key', $entity['key'])->whereIn('source_key', $existingKeys)->delete();
                    DB::table('d365_raw_records')->where('entity_key', $entity['key'])->whereIn('source_key', $existingKeys)->delete();
                }
                foreach (array_chunk(array_values($typedRows), 250) as $chunk) DB::table('d365_typed_records')->insert($chunk);
                foreach (array_chunk($rawRows, 250) as $chunk) DB::table('d365_raw_records')->insert($chunk);
            });

            $message = sprintf('Month staging replaced with %d posted rows from %d transactions; %d journal headers matched IsPosted = Yes. Unposted rows were skipped.', count($typedRows), $fetched, count($postedHeaders));
            DB::table('d365_sync_logs')->where('id', $logId)->update(['status' => 'success', 'fetched' => $fetched, 'inserted' => $inserted, 'updated' => $updated, 'pages' => (int) ceil($fetched / 1000), 'progress_percent' => 100, 'message' => $message, 'updated_at' => $now]);
            return ['status' => 'success', 'entity' => $entity, 'fetched' => $fetched, 'inserted' => $inserted, 'updated' => $updated, 'posted' => count($typedRows), 'headers' => count($postedHeaders)];
        } catch (\Throwable $exception) {
            $status = $this->stopRequested() ? 'stopped' : 'failed';
            DB::table('d365_sync_logs')->where('id', $logId)->update(['status' => $status, 'fetched' => $fetched, 'message' => $exception->getMessage(), 'updated_at' => now()]);
            return ['status' => $status, 'entity' => $entity, 'fetched' => $fetched, 'error' => $exception->getMessage()];
        }
    }

    public function syncAll(?int $limit = null, ?string $dateFrom = null, ?string $dateTo = null, bool $forceFull = false): array { $order = ['main_accounts', 'dimension_values', 'mapping_valuation_by_findims', 'vendors', 'customers', 'gl_journal_entries', 'gl_entries', 'ledger_journal_lines', 'ledger_entry_journals', 'inventory_movement_entries']; $results = []; foreach ($order as $key) { if ($this->stopRequested()) break; $results[] = $this->syncEntity($this->entityByKey($key), $limit, $dateFrom, $dateTo, $forceFull); } return $results; }
    public function requestStop(): void { Cache::put('d365_sync_stop', true, now()->addHour()); }
    public function clearStop(): void { Cache::forget('d365_sync_stop'); }
    public function stopRequested(): bool { return (bool) Cache::get('d365_sync_stop', false); }
    public function latestStatus(): array { return collect(self::ENTITIES)->map(function (array $entity): array { $log = DB::table('d365_sync_logs')->where('entity_key', $entity['key'])->latest('id')->first(); return $entity + ['last' => $log, 'staging_count' => DB::table('d365_raw_records')->where('entity_key', $entity['key'])->count(), 'typed_count' => DB::table('d365_typed_records')->where('entity_key', $entity['key'])->count()]; })->all(); }

    private function filter(array $entity, ?string $dateFrom, ?string $dateTo, bool $forceFull = false): ?string
    {
        $parts = [];
        $companyField = $entity['company_field'];
        if ($companyField && filled($entity['exclude_company'] ?? null)) $parts[] = $companyField." ne '".str_replace("'", "''", $entity['exclude_company'])."'";
        elseif ($companyField && filled($entity['company_value'] ?? config('services.d365.company'))) $parts[] = $companyField." eq '".str_replace("'", "''", $entity['company_value'] ?? config('services.d365.company'))."'";
        // A period-bounded sync must be driven by the entity's date field.
        // Applying the previous SourceKey cursor as well can skip journal
        // headers whose keys are not ordered by AccountingDate, leaving GL
        // detail rows without a header date and therefore without a period.
        if (! $forceFull && !$dateFrom && !$dateTo && $entity['mode'] === 'incremental' && ($entity['source_field'] ?? null)) {
            $cursor = DB::table('d365_sync_logs')->where('entity_key', $entity['key'])->where('status', 'success')->whereNotNull('max_source_key')->latest('id')->value('max_source_key');
            if ($cursor !== null && ctype_digit((string) $cursor)) $parts[] = $entity['source_field'].' gt '.(int) $cursor;
        }
        if ($entity['date_field'] && $dateFrom) $parts[] = $entity['date_field']." ge ".date('Y-m-d', strtotime($dateFrom));
        if ($entity['date_field'] && $dateTo) $parts[] = $entity['date_field']." le ".date('Y-m-d', strtotime($dateTo));
        return $parts ? implode(' and ', $parts) : null;
    }
    private function filtersForEntity(array $entity, ?string $dateFrom, ?string $dateTo, bool $forceFull = false): array
    {
        if (!$dateFrom && !$dateTo) return [$this->filter($entity, null, null, $forceFull)];
        if ($entity['key'] === 'gl_entries') {
            $keys = DB::table('d365_typed_records')->where('entity_key', 'gl_journal_entries')->when($dateFrom, fn ($query) => $query->whereDate('accounting_date', '>=', $dateFrom))->when($dateTo, fn ($query) => $query->whereDate('accounting_date', '<=', $dateTo))->pluck('source_key')->filter()->map(fn ($key): string => (string) $key)->unique()->values();
            // This entity has no date field. If the date-scoped journal lookup is empty,
            // do not fall back to an unfiltered request that could import all GL history.
            if ($keys->isEmpty()) return [];
            return $keys->chunk(50)->map(fn ($chunk): string => $this->appendFilter($this->filter($entity, $dateFrom, $dateTo, $forceFull), $chunk->map(fn (string $key): string => 'GeneralJournalEntry eq '.$key)->implode(' or ')))->all();
        }
        if ($entity['key'] === 'ledger_entry_journals') {
            $keys = DB::table('d365_typed_records')->where('entity_key', 'ledger_journal_lines')->when($dateFrom, fn ($query) => $query->whereDate('accounting_date', '>=', $dateFrom))->when($dateTo, fn ($query) => $query->whereDate('accounting_date', '<=', $dateTo))->pluck('journal_number')->filter()->map(fn ($key): string => (string) $key)->unique()->values();
            return $keys->chunk(50)->map(fn ($chunk): string => $this->appendFilter($this->filter($entity, $dateFrom, $dateTo, $forceFull), $chunk->map(fn (string $key): string => "JournalNumber eq '".str_replace("'", "''", $key)."'")->implode(' or ')))->all();
        }
        return [$this->filter($entity, $dateFrom, $dateTo, $forceFull)];
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
    private function date(mixed $value): ?string
    {
        if (! $value) return null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $value, $matches)) return $matches[1];
        try { return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'); } catch (\Throwable) { return null; }
    }
    private function dateTime(mixed $value): ?string
    {
        if (! $value) return null;
        try { return (new \DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); } catch (\Throwable) { return null; }
    }
}
