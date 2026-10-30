<?php

namespace App\Services\D365;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class D365AllocationService
{
    private ?Collection $activeRules = null;

    private function activeRules(): Collection
    {
        return $this->activeRules ??= DB::table('d365_allocation_mappings')->where('is_active', true)->get();
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
            if (filled($rule->account_code) && (string) $rule->account_code !== $account) return false;
            if (filled($rule->account_prefix) && !str_starts_with($account, (string) $rule->account_prefix)) return false;
            return true;
        })->sort(function ($a, $b): int {
            $priority = ((int) $b->priority) <=> ((int) $a->priority);
            if ($priority !== 0) return $priority;
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
            $result = $this->resolve(['account_code' => $row->account_code, 'dimensions' => $dimensions]);
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
}
