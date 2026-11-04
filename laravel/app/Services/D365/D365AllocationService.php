<?php

namespace App\Services\D365;

use App\Models\GlImportBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class D365AllocationService
{
    private ?Collection $activeRules = null;
    private ?Collection $mainAccounts = null;

    private function activeRules(): Collection
    {
        return $this->activeRules ??= DB::table('d365_allocation_mappings')->where('is_active', true)->get();
    }

    private function mainAccount(?string $sourceKey): ?object
    {
        $key = trim((string) $sourceKey);
        if ($key === '') return null;
        $this->mainAccounts ??= DB::table('d365_typed_records')
            ->where('entity_key', 'main_accounts')
            ->get(['source_key', 'account_code', 'account_name'])
            ->flatMap(function ($account): array {
                $value = ['account_code' => (string) $account->account_code, 'account_name' => (string) $account->account_name];
                return ['source:'.(string) $account->source_key => (object) $value, 'code:'.(string) $account->account_code => (object) $value];
            });
        return $this->mainAccounts->get('source:'.$key) ?: $this->mainAccounts->get('code:'.$key);
    }

    public function syncAccountHierarchy(): array
    {
        $accounts = DB::table('d365_typed_records')
            ->where('entity_key', 'main_accounts')
            ->get(['source_key', 'account_code', 'account_name', 'payload']);
        $sourceToCode = $accounts->mapWithKeys(fn ($account): array => ['source:'.(string) $account->source_key => (string) $account->account_code]);
        $childrenByParent = collect();
        $normalized = $accounts->map(function ($account) use ($sourceToCode, $childrenByParent): array {
            $payload = json_decode((string) $account->payload, true) ?: [];
            $parentKey = (string) ($payload['ParentMainAccount'] ?? $payload['parentMainAccount'] ?? '');
            $parentCode = $parentKey !== '' && $parentKey !== '0' ? ($sourceToCode->get('source:'.$parentKey) ?: null) : null;
            if ($parentCode) $childrenByParent->push($parentCode);
            return [
                'account_code' => (string) $account->account_code,
                'account_name' => (string) $account->account_name,
                'parent_code' => $parentCode,
            ];
        });
        $parentCodes = $childrenByParent->unique()->flip();
        $hasHierarchy = $normalized->contains(fn (array $account): bool => $account['parent_code'] !== null);
        $updated = 0;
        foreach ($normalized as $account) {
            if (!DB::table('cost_accounts')->where('account_code', $account['account_code'])->exists()) continue;
            $updates = [
                'head_account' => substr($account['account_code'], 0, 1),
                'updated_at' => now(),
            ];
            if ($hasHierarchy) {
                $updates['parent_code'] = $account['parent_code'];
                $updates['is_parent'] = $parentCodes->has($account['account_code']);
            }
            if ($account['account_name'] !== '') $updates['account_name'] = $account['account_name'];
            DB::table('cost_accounts')->where('account_code', $account['account_code'])->update($updates);
            $updated++;
        }
        $this->mainAccounts = null;
        return ['d365_accounts' => $accounts->count(), 'report_accounts_updated' => $updated];
    }

    public function resolve(array $row): array
    {
        $dimensions = array_change_key_case((array) ($row['dimensions'] ?? []), CASE_UPPER);
        $candidates = $this->activeRules()->filter(function ($rule) use ($row, $dimensions): bool {
            foreach ([
                ['code' => 'group_activity_code', 'name' => 'group_activity_name', 'legacy' => 'group_activity', 'source' => 'GROUP_ACTIVITY', 'source_name' => 'GROUP_ACTIVITY_NAME'],
                ['code' => 'activity_code', 'name' => 'activity_name', 'legacy' => 'activity', 'source' => 'ACTIVITY', 'source_name' => 'ACTIVITY_NAME'],
                ['code' => 'location_code', 'name' => 'location_name', 'legacy' => 'location', 'source' => 'LOCATION', 'source_name' => 'LOCATION_NAME'],
            ] as $dimension) {
                $actualCode = trim((string) ($dimensions[$dimension['source']] ?? $row[$dimension['source']] ?? ''));
                $actualName = trim((string) ($dimensions[$dimension['source_name']] ?? $row[$dimension['source_name']] ?? ''));
                $expectedCode = trim((string) ($rule->{$dimension['code']} ?? ''));
                $expectedName = trim((string) ($rule->{$dimension['name']} ?? ''));
                $legacy = trim((string) ($rule->{$dimension['legacy']} ?? ''));
                if ($expectedCode !== '' && strcasecmp($expectedCode, $actualCode) !== 0) return false;
                if ($expectedName !== '' && $expectedCode === '' && strcasecmp($expectedName, $actualName) !== 0) return false;
                if ($legacy !== '' && $expectedCode === '' && $expectedName === '' && strcasecmp($legacy, $actualCode ?: $actualName) !== 0) return false;
            }
            foreach (['department'] as $field) {
                $expected = trim((string) ($rule->{$field} ?? ''));
                if ($expected !== '' && strcasecmp($expected, trim((string) ($dimensions[strtoupper($field)] ?? $row[$field] ?? ''))) !== 0) return false;
            }
            $account = trim((string) ($row['account_code'] ?? ''));
            $accountMaster = $this->mainAccount($account);
            $account = $accountMaster?->account_code ?: $account;
            if (filled($rule->account_code) && (string) $rule->account_code !== $account) return false;
            if (filled($rule->account_prefix) && !str_starts_with($account, (string) $rule->account_prefix)) return false;
            return true;
        })->sort(function ($a, $b): int {
            $priority = ((int) $b->priority) <=> ((int) $a->priority);
            if ($priority !== 0) return $priority;
            $accountMatchA = filled($a->account_code) ? 2 : (filled($a->account_prefix) ? 1 : 0);
            $accountMatchB = filled($b->account_code) ? 2 : (filled($b->account_prefix) ? 1 : 0);
            if ($accountMatchA !== $accountMatchB) return $accountMatchB <=> $accountMatchA;
            $specificityA = collect(['account_code', 'account_prefix', 'activity', 'location', 'department', 'group_activity', 'group_activity_code', 'group_activity_name', 'activity_code', 'activity_name', 'location_code', 'location_name'])->sum(fn (string $field): int => filled($a->{$field}) ? 1 : 0);
            $specificityB = collect(['account_code', 'account_prefix', 'activity', 'location', 'department', 'group_activity', 'group_activity_code', 'group_activity_name', 'activity_code', 'activity_name', 'location_code', 'location_name'])->sum(fn (string $field): int => filled($b->{$field}) ? 1 : 0);
            return $specificityB <=> $specificityA;
        })->first();
        return [
            'valuation_to' => $candidates?->valuation_to,
            'costing_to' => $candidates?->costing_to,
            'label' => $candidates?->label,
            'mapping_status' => $candidates && filled($candidates->valuation_to) && filled($candidates->costing_to) ? 'mapped' : 'unmapped',
            'mapping_id' => $candidates?->id,
        ];
    }

    public function preview(?string $entity = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $rows = $this->stagingRows($entity ?: 'gl_entries', $dateFrom, $dateTo);
        $mapped = $unmapped = 0;
        $total = 0.0;
        foreach ($rows as $row) {
            $dimensions = json_decode((string) $row->dimensions, true) ?: [];
            $account = $this->mainAccount($row->account_code);
            $result = $this->resolve(['account_code' => $account?->account_code ?: $row->account_code, 'dimensions' => $dimensions]);
            $result['mapping_status'] === 'mapped' ? $mapped++ : $unmapped++;
            $total += (float) ($row->amount_reporting ?? $row->amount_accounting ?? 0);
        }
        return ['entity' => $entity ?: 'gl_entries', 'rows' => $rows->count(), 'mapped' => $mapped, 'unmapped' => $unmapped, 'total_reporting' => $total];
    }

    public function stagingRows(string $entity, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        $query = DB::table('d365_typed_records')->where('entity_key', $entity);
        if ($entity !== 'gl_entries') {
            if ($dateFrom) $query->whereDate('accounting_date', '>=', $dateFrom);
            if ($dateTo) $query->whereDate('accounting_date', '<=', $dateTo);
            return $query->get();
        }

        if (!$dateFrom && !$dateTo) return $query->get();

        $headerKeys = DB::table('d365_typed_records')
            ->where('entity_key', 'gl_journal_entries')
            ->when($dateFrom, fn ($builder) => $builder->whereDate('accounting_date', '>=', $dateFrom))
            ->when($dateTo, fn ($builder) => $builder->whereDate('accounting_date', '<=', $dateTo))
            ->pluck('source_key')
            ->map(fn ($key): string => (string) $key)
            ->filter()
            ->flip();

        if ($headerKeys->isEmpty()) return collect();

        return $query->get()->filter(function ($row) use ($headerKeys): bool {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $parentKey = $payload['GeneralJournalEntry'] ?? $payload['generalJournalEntry'] ?? null;
            return $parentKey !== null && $headerKeys->has((string) $parentKey);
        })->values();
    }

    public function importStaging(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $this->syncAccountHierarchy();
        $rows = $this->stagingRows('gl_entries', $dateFrom, $dateTo);
        $headerMeta = DB::table('d365_typed_records')
            ->where('entity_key', 'gl_journal_entries')
            ->when($dateFrom, fn ($builder) => $builder->whereDate('accounting_date', '>=', $dateFrom))
            ->when($dateTo, fn ($builder) => $builder->whereDate('accounting_date', '<=', $dateTo))
            ->get(['source_key', 'accounting_date', 'journal_number', 'voucher'])
            ->mapWithKeys(fn ($row): array => [(string) $row->source_key => $row])
            ->all();
        $months = collect();
        $batch = GlImportBatch::create(['filename' => 'd365-auto-sync.json', 'source' => 'd365', 'row_count' => $rows->count(), 'months_covered' => [], 'note' => 'Automatic import after D365 sync', 'imported_at' => now()]);
        $mapped = 0;
        $unmapped = 0;
        foreach ($rows->chunk(200) as $chunk) {
            $entries = $chunk->map(function ($row) use (&$mapped, &$unmapped, $batch, $headerMeta, $months): array {
                $dimensions = json_decode((string) $row->dimensions, true) ?: [];
                $account = $this->mainAccount($row->account_code);
                $accountCode = $account?->account_code ?: $row->account_code;
                $allocation = $this->resolve(['account_code' => $accountCode, 'dimensions' => $dimensions]);
                $allocation['mapping_status'] === 'mapped' ? $mapped++ : $unmapped++;
                $payload = json_decode((string) $row->payload, true) ?: [];
                $parentKey = (string) ($payload['GeneralJournalEntry'] ?? $payload['generalJournalEntry'] ?? '');
                $header = $headerMeta[$parentKey] ?? null;
                $entryDate = $row->accounting_date ?: ($header?->accounting_date ?? null);
                $period = $entryDate ? substr((string) $entryDate, 0, 7) : 'unknown';
                if ($period !== 'unknown') $months->push($period);
                $amount = $row->amount_accounting ?? $row->amount_reporting ?? 0;
                $amountIdr = strtoupper((string) $row->currency) === 'IDR' ? $row->amount_transaction : null;
                return ['batch_id' => $batch->id, 'journal_number' => $row->journal_number ?: ($header?->journal_number ?: $row->source_key), 'voucher' => $row->voucher ?: ($header?->voucher ?: null), 'entry_date' => $entryDate, 'year_closed' => 'No', 'account_name' => $account?->account_name ?: $row->account_name, 'description' => $row->description, 'currency' => $row->currency, 'fuel_quantity' => $row->quantity ?? 0, 'amount_txn' => $row->amount_transaction, 'amount_idr' => $amountIdr, 'amount' => $row->amount_accounting, 'amount_reporting' => $row->amount_reporting ?? $amount, 'posting_type' => $payload['PostingType'] ?? null, 'posting_layer' => $payload['PostingLayer'] ?? null, 'vendor_account' => $payload['VendorAccount'] ?? $payload['VendAccount'] ?? null, 'customer_account' => $payload['CustomerAccount'] ?? $payload['CustAccount'] ?? null, 'invoice_no' => $payload['Invoice'] ?? $payload['InvoiceId'] ?? null, 'main_account' => $accountCode, 'activity' => $dimensions['ACTIVITY'] ?? null, 'cash_flow' => $dimensions['CASH_FLOW'] ?? null, 'department' => $dimensions['DEPARTMENT'] ?? null, 'group_activity' => $dimensions['GROUP_ACTIVITY'] ?? null, 'location' => $dimensions['LOCATION'] ?? null, 'project' => $dimensions['PROJECT'] ?? null, 'project_name' => null, 'cost_center' => $dimensions['COST_CENTER'] ?? null, 'valuation_to' => $allocation['valuation_to'], 'costing_to' => $allocation['costing_to'], 'po_dt_number' => null, 'gjae_id' => $payload['GeneralJournalEntry'] ?? $payload['generalJournalEntry'] ?? null, 'created_datetime' => $payload['CreatedDateTime'] ?? $payload['CreatedOn'] ?? null, 'period' => $period, 'source_type' => 'd365', 'source_key' => $row->source_key, 'd365_entity' => 'gl_entries', 'mapping_status' => $allocation['mapping_status'], 'created_at' => now(), 'updated_at' => now()];
            })->values()->all();
            if ($entries) DB::table('gl_entries')->upsert($entries, ['source_type', 'source_key'], array_values(array_diff(array_keys($entries[0]), ['id', 'source_type', 'source_key', 'created_at'])));
        }
        $batch->update(['row_count' => $rows->count(), 'months_covered' => $months->unique()->values()->all()]);
        return ['batch' => $batch->fresh(), 'rows' => $rows->count(), 'mapped' => $mapped, 'unmapped' => $unmapped];
    }
}
