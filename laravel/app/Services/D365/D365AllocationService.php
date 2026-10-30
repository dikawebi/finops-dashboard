<?php

namespace App\Services\D365;

use Illuminate\Support\Facades\DB;

class D365AllocationService
{
    public function resolve(array $row): array
    {
        $dimensions = $row['dimensions'] ?? [];
        $candidates = DB::table('d365_allocation_mappings')->where('is_active', true)->get()->filter(function ($rule) use ($row, $dimensions): bool {
            foreach (['activity', 'location', 'department', 'group_activity'] as $field) {
                $expected = trim((string) ($rule->{$field} ?? ''));
                if ($expected !== '' && strcasecmp($expected, trim((string) ($dimensions[$field] ?? $row[$field] ?? ''))) !== 0) return false;
            }
            $account = trim((string) ($row['account_code'] ?? ''));
            if (filled($rule->account_code) && (string) $rule->account_code !== $account) return false;
            if (filled($rule->account_prefix) && !str_starts_with($account, (string) $rule->account_prefix)) return false;
            return true;
        })->sort(function ($a, $b): int {
            $priority = ((int) $b->priority) <=> ((int) $a->priority);
            if ($priority !== 0) return $priority;
            $specificityA = collect(['account_code', 'account_prefix', 'activity', 'location', 'department', 'group_activity'])->sum(fn (string $field): int => filled($a->{$field}) ? 1 : 0);
            $specificityB = collect(['account_code', 'account_prefix', 'activity', 'location', 'department', 'group_activity'])->sum(fn (string $field): int => filled($b->{$field}) ? 1 : 0);
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
        $query = DB::table('d365_typed_records')->where('entity_key', $entity ?: 'gl_entries');
        if ($dateFrom) $query->whereDate('accounting_date', '>=', $dateFrom);
        if ($dateTo) $query->whereDate('accounting_date', '<=', $dateTo);
        $rows = $query->get();
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
}
