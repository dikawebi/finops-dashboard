<?php

namespace App\Services;

use App\Models\MonthlyMetric;

/** Laravel port of the reference project's computeCostPerMT formula. */
class CostPerMtService
{
    public function __construct(private readonly CostMatrixService $costs) {}

    public function build(string $period, string $mode = 'mtd'): array
    {
        $current = $this->calculate($period, $mode);
        $previousPeriod = date('Y-m', strtotime($period.'-01 -1 month'));
        $previous = $this->calculate($previousPeriod, 'mtd');
        $previousStages = collect($previous['stages'])->keyBy('stage');
        $current['comparisonPeriod'] = $previousPeriod;
        $current['comparison'] = collect($current['stages'])->map(function (array $stage) use ($previousStages): array {
            $previousRate = $previousStages->get($stage['stage'])['costPerMT'] ?? null;
            $rate = $stage['costPerMT'];
            return ['stage'=>$stage['stage'],'currentCostPerMT'=>$rate,'previousCostPerMT'=>$previousRate,
                'change'=>$rate === null || $previousRate === null ? null : $rate - $previousRate,
                'changePct'=>$rate === null || $previousRate === null || (float) $previousRate === 0.0 ? null : ($rate - $previousRate) / abs($previousRate) * 100];
        })->values()->all();
        return $current;
    }

    private function calculate(string $period, string $mode): array
    {
        $matrix = $this->costs->build($period, $mode);
        $periods = $this->periods($period, $mode);
        $metrics = MonthlyMetric::whereIn('period', $periods)->get()->groupBy('metric_key');
        $volume = function (string $key) use ($metrics, $periods): array {
            $byMonth = $metrics->get($key, collect())->keyBy('period');
            $missing = array_values(array_filter($periods, static fn (string $month): bool => !$byMonth->has($month) || $byMonth->get($month)->value === null));
            $total = $byMonth->sum(static fn (MonthlyMetric $item): float => (float) $item->value);
            return ['value'=>$total,'missing'=>$missing,'complete'=>!$missing];
        };

        $coal = $volume('coalGetting');
        $haulingParts = array_map($volume, ['haulingBuaWp','haulingBuaPort','haulingWpPort']);
        $hauling = ['value'=>array_sum(array_column($haulingParts, 'value')),
            'missing'=>array_values(array_unique(array_merge(...array_column($haulingParts, 'missing')))),
            'complete'=>count(array_filter($haulingParts, static fn (array $item): bool => $item['complete'])) === count($haulingParts)];
        $wash = $volume('washFeed');
        $port = $volume('portLoading');
        // Overall cost/MT uses Coal Getting as its production denominator. A
        // missing stage-specific metric must not invalidate this overall rate.
        $incompleteVolume = $mode === 'ytd' && !$coal['complete'];
        $grand = $matrix['grandTotal'];
        $stages = [
            ['stage'=>'Mining','valuationCost'=>$grand['valMining'],'operatingCost'=>$grand['costMining'],'totalCost'=>$this->add($grand['valMining'],$grand['costMining']),'volume'=>$coal],
            ['stage'=>'Coal Hauling','valuationCost'=>$grand['valHauling'],'operatingCost'=>$grand['costHauling'],'totalCost'=>$this->add($grand['valHauling'],$grand['costHauling']),'volume'=>$hauling],
            ['stage'=>'Washing Plant','valuationCost'=>$grand['valWP'],'operatingCost'=>$grand['costWP'],'totalCost'=>$this->add($grand['valWP'],$grand['costWP']),'volume'=>$wash],
            ['stage'=>'BP PORT & Jetty','valuationCost'=>$grand['valPort'],'operatingCost'=>$grand['costPort'],'totalCost'=>$this->add($grand['valPort'],$grand['costPort']),'volume'=>$port],
            ['stage'=>'General Admin','valuationCost'=>$grand['valNotValuated'],'operatingCost'=>$grand['costGA'],'totalCost'=>$grand['directGA'],'volume'=>$coal],
            ['stage'=>'Selling Expense','valuationCost'=>'0','operatingCost'=>$grand['costSelling'],'totalCost'=>$grand['costSelling'],'volume'=>$port],
        ];

        foreach ($stages as &$stage) {
            $item = $stage['volume'];
            $stageVolume = $item['complete'] ? $item['value'] : null;
            $stage['metricTons'] = $stageVolume;
            $stage['costPerMT'] = $stageVolume !== null && $stageVolume > 0 && !$incompleteVolume ? (float) $stage['totalCost'] / $stageVolume : null;
            $stage['costPerTotalMT'] = $coal['complete'] && $coal['value'] > 0 && !$incompleteVolume ? (float) $stage['totalCost'] / $coal['value'] : null;
            unset($stage['volume']);
        }
        unset($stage);

        $accountGroups = $this->accountGroups($matrix['rows']);
        $volumeMissing = $coal['missing'];
        $stageVolumeMissing = [
            'coalGetting'=>$coal['missing'],'hauling'=>array_values(array_unique(array_merge(...array_column($haulingParts, 'missing')))),
            'washFeed'=>$wash['missing'],'portLoading'=>$port['missing'],
        ];
        return ['currency'=>'USD','amountScale'=>1,'mode'=>$mode,'period'=>$period,
            'dataSource'=>$matrix['dataSource'] ?? 'none','missingVolumePeriods'=>$volumeMissing,
            'stageMissingVolumePeriods'=>$stageVolumeMissing,'incompleteVolume'=>$incompleteVolume,'volumeSource'=>'Google Sheets DB','totalVolumeProducedMT'=>$coal['value'],
            'grandTotalCost'=>$grand['allCost'],'overallCostPerMT'=>$coal['value'] > 0 && !$incompleteVolume ? (float) $grand['allCost'] / $coal['value'] : null,
            'stages'=>$stages,'costByAccountGroup'=>$accountGroups];
    }

    private function accountGroups(array $rows): array
    {
        $leaf = array_values(array_filter($rows, static fn (array $row): bool => !($row['isParent'] ?? false)));
        $groups = [
            ['Fuel & Energy', static fn (string $code): bool => $code === '5000601'],
            ['Salary & Employee Benefits', static fn (string $code): bool => str_starts_with($code, '500005')],
            ['Equipment & Vehicle Rent', static fn (string $code): bool => str_starts_with($code, '500010')],
            ['Labour Outsourcing & Overheads', static fn (string $code): bool => str_starts_with($code, '500170') || $code === '5000402'],
            ['Repair & Maintenance', static fn (string $code): bool => str_starts_with($code, '500080')],
            ['Freight & Handling', static fn (string $code): bool => str_starts_with($code, '500030')],
            ['Consultant & Professional Fee', static fn (string $code): bool => str_starts_with($code, '500050')],
        ];
        $assigned = [];
        $result = [];
        foreach ($groups as [$label, $match]) {
            $amount = 0.0;
            foreach ($leaf as $row) if ($match((string) $row['code'])) { $amount += (float) $row['allCost']; $assigned[(string) $row['code']] = true; }
            $result[] = ['group'=>$label,'amount'=>$amount];
        }
        $other = 0.0;
        foreach ($leaf as $row) if (!isset($assigned[(string) $row['code']])) $other += (float) $row['allCost'];
        $result[] = ['group'=>'Others, Travel & Utilities','amount'=>$other];
        return $result;
    }

    private function add(mixed $a, mixed $b): string { return bcadd((string) $a, (string) $b, 6); }

    private function periods(string $period, string $mode): array
    {
        if ($mode !== 'ytd') return [$period];
        return array_map(static fn (int $month): string => substr($period, 0, 4).'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT), range(1, (int) substr($period, 5, 2)));
    }
}
