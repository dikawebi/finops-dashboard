<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Reproduces the 3000 WA COGP-COGS internal available-stock costing from live Sheets inputs. */
class InternalWaCostPerMtService
{
    public function calculate(string $period): array
    {
        $year = substr($period, 0, 4);
        $monthNumber = (int) substr($period, 5, 2);
        $requiredPeriods = array_map(static fn (int $month): string => sprintf('%04d-%02d', (int) $year, $month), range(1, $monthNumber));
        $records = DB::table('google_sheets_internal_wa_periods')->whereIn('period', $requiredPeriods)->orderBy('period')->get()->keyBy('period');
        $missing = array_values(array_filter($requiredPeriods, static fn (string $month): bool => !$records->has($month)));
        if ($missing) return ['available'=>false,'missingPeriods'=>$missing,'stages'=>[],'validationWarnings'=>[],'sourceSyncedAt'=>null];

        $payloads = $records->mapWithKeys(static fn ($row): array => [$row->period=>json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR)])->all();
        $balances = $payloads[$requiredPeriods[0]]['openingBalances'] ?? [];
        foreach (['bua','washed','port','kunz'] as $location) $balances[$location] = [
            'qtyMT'=>(float) ($balances[$location]['qtyMT'] ?? 0),
            'valueUSD'=>(float) ($balances[$location]['valueUSD'] ?? 0),
        ];
        $selectedAvailable = [];
        $selectedAddedCosts = [];
        $warnings = [];

        foreach ($requiredPeriods as $month) {
            $data = $payloads[$month];
            $qty = array_map(static fn (array $balance): float => $balance['qtyMT'], $balances);
            $value = array_map(static fn (array $balance): float => $balance['valueUSD'], $balances);
            $amount = static fn (string $key): float => (float) ($data[$key] ?? 0);
            $rate = static fn (float $quantity, float $usd): float => abs($quantity) > 1e-12 ? $usd / $quantity : 0.0;

            // BUA: current-period mining cost is a receipt; allocated overhead is an added cost.
            $qty['bua'] += $amount('coalGetting');
            $value['bua'] += $amount('miningCostUSD') + $amount('overheadBuaUSD');
            $available['bua'] = ['qtyMT'=>$qty['bua'],'valueUSD'=>$value['bua'],'rateUSD'=>$rate($qty['bua'],$value['bua'])];
            $buaRate = $available['bua']['rateUSD'];
            $buaWpValue = $amount('buaToWP') * $buaRate;
            $buaPortValue = $amount('buaToPort') * $buaRate;
            $qty['bua'] -= $amount('buaToWP') + $amount('buaToPort');
            $value['bua'] -= $buaWpValue + $buaPortValue;

            // The reference internal view transfers BUA-to-WP directly into the Wash pool.
            $qty['washed'] += $amount('buaToWP');
            $value['washed'] += $buaWpValue + $amount('washActivityCostUSD') + $amount('overheadWashUSD');
            $selectedAvailable['washed'] = ['qtyMT'=>$qty['washed'],'valueUSD'=>$value['washed']];
            $washRate = $rate($qty['washed'],$value['washed']);
            $washPortValue = $amount('wpToPort') * $washRate;
            $qty['washed'] -= $amount('wpToPort');
            $value['washed'] -= $washPortValue;

            // BP Port availability includes both receipts, route/handling cost, and allocated overhead.
            $qty['port'] += $amount('buaToPort') + $amount('wpToPort');
            $value['port'] += $buaPortValue + $washPortValue + $amount('portDirectRouteCostUSD')
                + $amount('portWpRouteCostUSD') + $amount('portLoadingCostUSD') + $amount('overheadPortUSD');
            $selectedAvailable['port'] = ['qtyMT'=>$qty['port'],'valueUSD'=>$value['port']];
            $portRate = $rate($qty['port'],$value['port']);
            $toIspValue = $amount('portToIsp') * $portRate;
            $qty['port'] -= $amount('portToIsp') + $amount('portSales');
            $value['port'] -= $toIspValue + $amount('portSales') * $portRate;

            // ISP availability is measured after transhipment cost and its allocation, before sales.
            $qty['kunz'] += $amount('portToIsp');
            $value['kunz'] += $toIspValue + $amount('ispTransportCostUSD') + $amount('overheadIspUSD');
            $selectedAvailable['kunz'] = ['qtyMT'=>$qty['kunz'],'valueUSD'=>$value['kunz']];
            $ispRate = $rate($qty['kunz'],$value['kunz']);
            $qty['kunz'] -= $amount('ispSales');
            $value['kunz'] -= $amount('ispSales') * $ispRate;
            $qty['kunz'] += $amount('ispHistoricalStockAdjustment');

            $balances = [
                'bua'=>['qtyMT'=>$qty['bua'],'valueUSD'=>$value['bua']],
                'washed'=>['qtyMT'=>$qty['washed'],'valueUSD'=>$value['washed']],
                'port'=>['qtyMT'=>$qty['port'],'valueUSD'=>$value['port']],
                'kunz'=>['qtyMT'=>$qty['kunz'],'valueUSD'=>$value['kunz']],
            ];
            $expected = $data['expectedClosing'] ?? [];
            foreach ($balances as $location=>$balance) {
                $sheet = $expected[$location] ?? null;
                if (!$sheet) { $warnings[] = "{$month}: source closing balance missing for {$location}."; continue; }
                if (abs($balance['qtyMT'] - (float) $sheet['qtyMT']) > 0.02 || abs($balance['valueUSD'] - (float) $sheet['valueUSD']) > 0.05) {
                    $warnings[] = sprintf('%s: %s closing does not reconcile (calculated %.2f MT / USD %.2f; sheet %.2f MT / USD %.2f).', $month, $location, $balance['qtyMT'], $balance['valueUSD'], (float) $sheet['qtyMT'], (float) $sheet['valueUSD']);
                }
            }
            if ($month === $period) {
                $selectedAvailable['bua'] = $available['bua'];
                $selectedAddedCosts = [
                    'bua'=>$amount('overheadBuaUSD'),
                    'washed'=>$amount('washActivityCostUSD') + $amount('overheadWashUSD'),
                    'port'=>$amount('portDirectRouteCostUSD') + $amount('portWpRouteCostUSD') + $amount('portLoadingCostUSD') + $amount('overheadPortUSD'),
                    'kunz'=>$amount('ispTransportCostUSD') + $amount('overheadIspUSD'),
                ];
            }
        }

        $labels = ['bua'=>'Mining','washed'=>'Washing Plant','port'=>'BP PORT & Jetty','kunz'=>'ISP'];
        $stages = [];
        foreach ($labels as $id=>$label) {
            $stock = $selectedAvailable[$id] ?? null;
            $total = $stock === null ? null : (float) $stock['valueUSD'];
            $quantity = $stock === null ? null : (float) $stock['qtyMT'];
            $addedCost = (float) ($selectedAddedCosts[$id] ?? 0);
            $stages[] = ['stage'=>$label,'valuationCost'=>$total === null ? null : $total - $addedCost,'operatingCost'=>$total === null ? null : $addedCost,
                'totalCost'=>$total,'metricTons'=>$quantity,'costPerMT'=>$quantity > 0 ? $total / $quantity : null,'costPerTotalMT'=>null];
        }
        $last = $records->get($period);
        return ['available'=>true,'period'=>$period,'source'=>'Google Sheets · WA COGP-COGS','sourceSyncedAt'=>$last?->source_synced_at,
            'missingPeriods'=>[],'stages'=>$stages,'validationWarnings'=>$warnings];
    }
}
