<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Inventory roll-forward matching localhost:3000's WA COGP-COGS internal model. */
class InternalWaInventoryService
{
    private const LOCATIONS = [
        'bua'=>'BUA / Mine Site', 'nonWash'=>'Wash Plant — Non Wash', 'washed'=>'Wash Plant — Wash',
        'highAsh'=>'High Ash', 'port'=>'BP PORT', 'transitPort'=>'In Transit to Port', 'kunz'=>'Kunz',
        'transitKunz'=>'In Transit to Kunz', 'transitBuaWp'=>'In Transit BUA to WP',
        'transitBuaPort'=>'In Transit BUA to Port', 'transitWpPort'=>'In Transit WP to Port',
    ];

    public function build(string $period, string $mode = 'mtd'): array
    {
        $year = (int) substr($period, 0, 4);
        $monthNumber = (int) substr($period, 5, 2);
        $requiredPeriods = array_map(static fn (int $month): string => sprintf('%04d-%02d', $year, $month), range(1, $monthNumber));
        $records = DB::table('google_sheets_internal_wa_periods')->whereIn('period', $requiredPeriods)->orderBy('period')->get()->keyBy('period');
        $missing = array_values(array_filter($requiredPeriods, static fn (string $month): bool => !$records->has($month)));
        if ($missing) return ['available'=>false,'periodKey'=>$period,'missingPeriods'=>$missing,'stages'=>[],'validationWarnings'=>[]];

        $payloads = $records->mapWithKeys(static fn ($row): array => [$row->period=>json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR)])->all();
        $operationalMetrics = DB::table('monthly_metrics')
            ->whereIn('period', $requiredPeriods)
            ->whereIn('metric_key', ['washFeed', 'washReject'])
            ->get(['period', 'metric_key', 'value'])
            ->keyBy(static fn ($row): string => $row->period.'|'.$row->metric_key);
        $internalWPOperational = [];
        foreach ($requiredPeriods as $month) {
            $wa = $payloads[$month];
            $metric = static function (string $key) use ($operationalMetrics, $month): ?float {
                $value = $operationalMetrics->get($month.'|'.$key)?->value;
                return $value === null ? null : (float) $value;
            };
            $internalWPOperational[$month] = [
                'feedMT' => $metric('washFeed'),
                'rejectMT' => $metric('washReject'),
                'cleanCoalMT' => isset($wa['washedCoalProduction']) ? (float) $wa['washedCoalProduction'] : null,
                'fromBuaMT' => isset($wa['buaToWP']) ? (float) $wa['buaToWP'] : null,
                'toPortMT' => isset($wa['wpToPort']) ? (float) $wa['wpToPort'] : null,
            ];
        }
        $opening = $payloads[$requiredPeriods[0]]['openingBalances'] ?? [];
        foreach (['bua','washed','port','kunz'] as $id) $opening[$id] = [
            'qtyMT'=>(float) ($opening[$id]['qtyMT'] ?? 0), 'valueUSD'=>(float) ($opening[$id]['valueUSD'] ?? 0),
        ];
        $modelOpeningBalances = $opening;
        $history = [];
        $monthStages = [];
        $validationWarnings = [];
        foreach ($requiredPeriods as $month) {
            [$result, $opening, $monthWarnings] = $this->calculateMonth($month, $opening, $payloads[$month]);
            $history[] = $result['history'];
            $monthStages[$month] = $result['stages'];
            array_push($validationWarnings, ...$monthWarnings);
        }

        $selectedHistory = $mode === 'ytd' ? $history : [end($history)];
        $selectedMonthStages = $mode === 'ytd' ? array_values($monthStages) : [end($monthStages)];
        $currentStages = end($monthStages);
        $firstStages = $monthStages[$requiredPeriods[0]];
        $displayStages = [];
        foreach ($currentStages as $stage) {
            if ($mode !== 'ytd') { $displayStages[] = $stage; continue; }
            $monthly = array_map(static fn (array $entry): array => current(array_filter($entry, static fn (array $row): bool => $row['id'] === $stage['id'])), $selectedMonthStages);
            $summed = [];
            foreach (['inflowQtyMT','receiptValueUSD','addedCostUSD','outflowQtyMT','outflowValueUSD','adjustmentQtyMT','adjustmentValueUSD'] as $field) {
                $summed[$field] = array_sum(array_map(static fn (array $item): float => (float) ($item[$field] ?? 0), $monthly));
            }
            $firstStage = current(array_filter($firstStages, static fn (array $row): bool => $row['id'] === $stage['id']));
            $ytdRows = [];
            foreach ($selectedMonthStages as $index=>$entry) {
                $monthStage = current(array_filter($entry, static fn (array $row): bool => $row['id'] === $stage['id']));
                foreach ($monthStage['rows'] as $movementRow) {
                    $movementRow['label'] = $selectedHistory[$index]['periodKey'].' — '.$movementRow['label'];
                    $ytdRows[] = $movementRow;
                }
            }
            $stage = array_merge($stage, $summed, [
                'beginningQtyMT'=>$firstStage['beginningQtyMT'], 'beginningValueUSD'=>$firstStage['beginningValueUSD'],
                'movingAverageRateUSD'=>$summed['outflowQtyMT'] ? $summed['outflowValueUSD'] / $summed['outflowQtyMT'] : null,
                'rows'=>$ytdRows,
            ]);
            $displayStages[] = $stage;
        }

        $current = end($history);
        $sourceSyncedAt = $records->get($period)?->source_synced_at;
        if ($sourceSyncedAt) $sourceSyncedAt = \Illuminate\Support\Carbon::parse($sourceSyncedAt, 'UTC')->toIso8601String();
        $monthsWithData = array_values(array_filter($requiredPeriods, static fn (string $month): bool => isset($payloads[$month])));
        $warningList = array_values(array_unique($validationWarnings));
        $sourceValues = $payloads[$period];
        $portRouteCost = (float) $sourceValues['portDirectRouteCostUSD'] + (float) $sourceValues['portWpRouteCostUSD'];

        return [
            'available'=>true,'currency'=>'USD','amountScale'=>1,'mode'=>$mode,'period'=>$period,'periodKey'=>$period,
            'startPeriod'=>$requiredPeriods[0],'rangeStart'=>$mode === 'ytd' ? $requiredPeriods[0] : $period,
            'reportType'=>'internal','reportBasis'=>'Internal inventory follows localhost:3000 WA COGP-COGS. Opening balances, monthly movements, and costs are read from the live Google Sheets tab; the October roll-forward does not use the September baseline or D365 GL costs.',
            'dataSource'=>'google-sheets-wa-cogp-cogs','dynamicSourceStatus'=>'active','quantitySource'=>'WA COGP-COGS',
            'costSource'=>'WA COGP-COGS','sourceSyncedAt'=>$sourceSyncedAt,
            'internalWASync'=>['source'=>'Google Sheets · WA COGP-COGS','syncedAt'=>$sourceSyncedAt],
            'internalWPOperational'=>$internalWPOperational,
            'internalWAMovementPeriods'=>$monthsWithData,'internalWAMissingPeriods'=>[],'internalAllocationWarnings'=>[],
            'modelOpeningBalances'=>$modelOpeningBalances,'openingSource'=>'google-sheets-wa-cogp-cogs',
            'stages'=>$displayStages,'history'=>$history,
            'totalEndingQtyMT'=>$current['totalEndingQtyMT'],'totalEndingValueUSD'=>$current['totalEndingValueUSD'],
            'overallAvgRateUSD'=>$current['totalEndingQtyMT'] ? $current['totalEndingValueUSD'] / $current['totalEndingQtyMT'] : 0,
            'cogsShippedQtyMT'=>$current['cogsShippedQtyMT'],'cogsShippedValUSD'=>$current['cogsShippedValUSD'],
            'cogsSellingCostUSD'=>$current['cogsSellingCostUSD'],'externalTransferValueUSD'=>0,
            'historyIssues'=>array_values(array_unique(array_merge(...array_map(static fn (array $entry): array => $entry['issues'], $history)))),
            'buaHistory'=>array_map(static function (string $month, array $stages): array {
                $bua = current(array_filter($stages, static fn (array $stage): bool => $stage['id'] === 'bua'));
                return array_merge($bua, ['period'=>$month]);
            }, array_keys($monthStages), array_values($monthStages)),
            'issues'=>$current['issues'],'validationWarnings'=>$warningList,
            'isProvisional'=>(bool) $warningList,'openingBalancesPending'=>false,'missingCostPeriods'=>[],'missingQuantityPeriods'=>[],'unallocatedHauling'=>[],
            'costMapping'=>[
                'source'=>'wa-cogp-cogs','hasData'=>true,'haulingBUAToWPUSD'=>0.0,
                'haulingBUAToPortUSD'=>(float) $sourceValues['portDirectRouteCostUSD'],
                'haulingWPToPortUSD'=>(float) $sourceValues['portWpRouteCostUSD'],
                'portAddedCostUSD'=>$portRouteCost + (float) $sourceValues['portLoadingCostUSD'],
                'portLoadingCostUSD'=>(float) $sourceValues['portLoadingCostUSD'],
            ],
            'sourceRowCounts'=>['waPeriods'=>count($records)],
            'liveMovements'=>[
                'mined'=>(float) $sourceValues['coalGetting'],'buaToWp'=>(float) $sourceValues['buaToWP'],
                'buaToPort'=>(float) $sourceValues['buaToPort'],'wpToPort'=>(float) $sourceValues['wpToPort'],
                'portToIsp'=>(float) $sourceValues['portToIsp'],'portSales'=>(float) $sourceValues['portSales'],
                'ispSales'=>(float) $sourceValues['ispSales'],
            ],
            'manualInputs'=>[],'manualSalesQtyMT'=>0,'manualSalesRevenueUSD'=>0,
        ];
    }

    private function calculateMonth(string $period, array $opening, array $data): array
    {
        $stages = [];
        $qty = $value = [];
        foreach (self::LOCATIONS as $id=>$name) {
            $qty[$id] = (float) ($opening[$id]['qtyMT'] ?? 0);
            $value[$id] = (float) ($opening[$id]['valueUSD'] ?? 0);
            $stages[$id] = ['id'=>$id,'name'=>$name,'location'=>$name,'includedInTotal'=>true,'includedInQuantityTotal'=>true,'includedInValueTotal'=>true,
                'rows'=>[],'beginningQtyMT'=>$qty[$id],'beginningValueUSD'=>$value[$id]];
            $openingRate = $qty[$id] ? $value[$id] / $qty[$id] : 0.0;
            $stages[$id]['rows'][] = ['label'=>'Opening balance','qtyMT'=>$qty[$id],'valueUSD'=>$value[$id],'rateUSD'=>$openingRate,'type'=>'opening'];
        }
        $n = static fn (string $key): float => (float) ($data[$key] ?? 0);
        foreach ([
            ['bua','Coal mined — COGP BUA',$n('coalGetting'),$n('miningCostUSD'),'receipt','COGP BUA'],
            ['bua','Allocated Internal Overhead',0.0,$n('overheadBuaUSD'),'cost','Internal allocation'],
        ] as $line) $this->add($stages,$qty,$value,...$line);
        $this->available($stages,$qty,$value,'bua');
        $this->outbound($stages,$qty,$value,'bua',$n('buaToWP'),'Transfer BUA to Washing Plant','washed');
        $this->outbound($stages,$qty,$value,'bua',$n('buaToPort'),'Transfer BUA to BP PORT','port');

        $this->add($stages,$qty,$value,'washed','Add Cost — BUA to WP and Washing Plant',0.0,$n('washActivityCostUSD'),'cost','Report Cost');
        $this->add($stages,$qty,$value,'washed','Allocated Internal Overhead',0.0,$n('overheadWashUSD'),'cost','Internal allocation');
        $this->available($stages,$qty,$value,'washed');
        $this->outbound($stages,$qty,$value,'washed',$n('wpToPort'),'Transfer Washing Plant to BP PORT','port');

        $portAddCost = $n('portDirectRouteCostUSD') + $n('portWpRouteCostUSD') + $n('portLoadingCostUSD');
        $this->add($stages,$qty,$value,'port','Add Cost — hauling and BP PORT',0.0,$portAddCost,'cost','WA COGP-COGS');
        $this->add($stages,$qty,$value,'port','Allocated Internal Overhead',0.0,$n('overheadPortUSD'),'cost','Internal allocation');
        $this->available($stages,$qty,$value,'port');
        $portSale = $this->outbound($stages,$qty,$value,'port',$n('portSales'),'Sales',null);
        $ispReceipt = $this->outbound($stages,$qty,$value,'port',$n('portToIsp'),'Transfer to ISP KUNZ','kunz');

        $this->add($stages,$qty,$value,'kunz','Port to ISP transhipment',0.0,$n('ispTransportCostUSD'),'cost','WA COGP-COGS');
        $this->add($stages,$qty,$value,'kunz','Allocated Internal Overhead',0.0,$n('overheadIspUSD'),'cost','Internal allocation');
        $this->available($stages,$qty,$value,'kunz');
        $ispSale = $this->outbound($stages,$qty,$value,'kunz',$n('ispSales'),'Sales',null);
        if (abs($n('ispHistoricalStockAdjustment')) > 1e-8) $this->add($stages,$qty,$value,'kunz','Historical WA stock balance alignment',$n('ispHistoricalStockAdjustment'),0.0,'historical','WA COGP-COGS');
        foreach (['nonWash','highAsh','transitPort','transitKunz','transitBuaWp','transitBuaPort','transitWpPort'] as $id) $this->available($stages,$qty,$value,$id);

        $issues = [];
        foreach ($stages as $id=>&$stage) {
            $stage['beginningRateUSD'] = $stage['beginningQtyMT'] ? $stage['beginningValueUSD'] / $stage['beginningQtyMT'] : 0.0;
            $sum = fn (array $types, string $field): float => array_sum(array_map(static fn (array $row): float => in_array($row['type'], $types, true) ? (float) $row[$field] : 0.0, $stage['rows']));
            $stage['inflowQtyMT'] = $sum(['receipt'],'qtyMT');
            $stage['receiptValueUSD'] = $sum(['receipt'],'valueUSD');
            $stage['addedCostUSD'] = $sum(['cost'],'valueUSD');
            $stage['inflowCostValuationUSD'] = $stage['addedCostUSD'] + ($id === 'bua' ? $n('miningCostUSD') : 0.0);
            $stage['inflowTotalValueUSD'] = $stage['receiptValueUSD'] + $stage['addedCostUSD'];
            $stage['outflowQtyMT'] = -$sum(['outflow'],'qtyMT');
            $stage['outflowValueUSD'] = -$sum(['outflow'],'valueUSD');
            $stage['adjustmentQtyMT'] = $sum(['adjustment'],'qtyMT');
            $stage['adjustmentValueUSD'] = $sum(['adjustment'],'valueUSD');
            $stage['endingQtyMT'] = abs($qty[$id]) < 1e-9 ? 0.0 : $qty[$id];
            $stage['endingValueUSD'] = abs($value[$id]) < 1e-9 ? 0.0 : $value[$id];
            $stage['endingRateUSD'] = $stage['endingQtyMT'] ? $stage['endingValueUSD'] / $stage['endingQtyMT'] : 0.0;
            $stage['costSource'] = 'wa-cogp-cogs';
            $stage['quantitySource'] = 'wa-cogp-cogs';
            $stage['rows'][] = ['label'=>'Closing balance','qtyMT'=>$stage['endingQtyMT'],'valueUSD'=>$stage['endingValueUSD'],'rateUSD'=>$stage['endingRateUSD'],'type'=>'closing'];
            if (!$stage['endingQtyMT'] && abs($stage['endingValueUSD']) > 0.005) $issues[] = "{$stage['name']}: zero quantity with remaining value.";
            if ($stage['endingQtyMT'] < -1e-8) $issues[] = "{$stage['name']}: negative tonnage balance; check WA movements.";
        }
        unset($stage);

        $totals = fn (string $field): float => array_sum(array_map(static fn (array $stage): float => $stage['includedIn'.($field === 'endingQtyMT' ? 'Quantity' : 'Value').'Total'] ? (float) $stage[$field] : 0.0, $stages));
        $cogsSellingCost = ($portSale['qtyMT'] ? $n('portSellingCostUSD') : 0.0) + ($ispSale['qtyMT'] ? $n('ispSellingCostUSD') : 0.0);
        $history = ['periodKey'=>$period,'totalEndingQtyMT'=>$totals('endingQtyMT'),'totalEndingValueUSD'=>$totals('endingValueUSD'),
            'cogsShippedQtyMT'=>$portSale['qtyMT'] + $ispSale['qtyMT'],'cogsShippedValUSD'=>$portSale['valueUSD'] + $ispSale['valueUSD'] + $cogsSellingCost,
            'cogsSellingCostUSD'=>$cogsSellingCost,'externalTransferValueUSD'=>0.0,'hasCostData'=>true,'costSource'=>'wa-cogp-cogs','quantitySource'=>'wa-cogp-cogs','issues'=>$issues];

        $expected = $data['expectedClosing'] ?? [];
        foreach (['bua','washed','port','kunz'] as $id) {
            if (!isset($expected[$id])) { $issues[] = "{$period}: WA closing balance is missing for {$id}."; continue; }
            $stage = $stages[$id];
            if (abs($stage['endingQtyMT'] - (float) $expected[$id]['qtyMT']) > 0.02 || abs($stage['endingValueUSD'] - (float) $expected[$id]['valueUSD']) > 0.05) {
                $issues[] = sprintf('%s: %s closing does not reconcile to Google Sheets WA COGP-COGS.', $period, $stage['name']);
            }
        }
        $history['issues'] = $issues;
        return [['stages'=>array_values($stages),'history'=>$history], $balances = array_map(static fn (array $stage): array => ['qtyMT'=>$stage['endingQtyMT'],'valueUSD'=>$stage['endingValueUSD']], array_intersect_key($stages, array_flip(['bua','washed','port','kunz']))), $issues];
    }

    private function add(array &$stages, array &$qty, array &$value, string $id, string $label, float $quantity, float $amount, string $type, string $source = ''): void
    {
        $qty[$id] += $quantity; $value[$id] += $amount;
        $rate = abs($quantity) > 1e-12 ? $amount / $quantity : (($qty[$id] != 0) ? $value[$id] / $qty[$id] : null);
        $row = ['label'=>$label,'qtyMT'=>$quantity,'valueUSD'=>$amount,'rateUSD'=>$rate,'type'=>$type];
        if ($source !== '') $row['source'] = $source;
        $stages[$id]['rows'][] = $row;
    }

    private function available(array &$stages, array &$qty, array &$value, string $id): void
    {
        $rate = $qty[$id] ? $value[$id] / $qty[$id] : 0.0;
        $stages[$id]['availableQtyMT'] = $qty[$id];
        $stages[$id]['availableValueUSD'] = $value[$id];
        $stages[$id]['movingAverageRateUSD'] = $rate;
        $stages[$id]['rows'][] = ['label'=>'Available','qtyMT'=>$qty[$id],'valueUSD'=>$value[$id],'rateUSD'=>$rate,'type'=>'available'];
    }

    private function outbound(array &$stages, array &$qty, array &$value, string $id, float $quantity, string $label, ?string $to): array
    {
        $rate = (float) ($stages[$id]['movingAverageRateUSD'] ?? 0);
        $amount = $quantity * $rate;
        if ($quantity) {
            $this->add($stages,$qty,$value,$id,$label,-$quantity,-$amount,'outflow',$to ?? 'sale');
            if ($to !== null) $this->add($stages,$qty,$value,$to,'Received from '.$stages[$id]['name'],$quantity,$amount,'receipt',$id);
        } elseif ($id === 'bua') {
            $stages[$id]['rows'][] = ['label'=>$label,'qtyMT'=>0.0,'valueUSD'=>0.0,'rateUSD'=>$rate,'type'=>'outflow','source'=>$to ?? 'sale'];
        }
        return ['qtyMT'=>$quantity,'valueUSD'=>$amount];
    }
}
