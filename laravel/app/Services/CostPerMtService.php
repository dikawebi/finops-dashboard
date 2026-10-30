<?php
namespace App\Services;
use App\Models\MonthlyMetric;
class CostPerMtService
{
    public function build(string $period, string $mode = 'mtd'): array
    {
        $baselinePath = base_path("../baseline/reference_dump/cost-per-mt_{$period}_{$mode}.json");
        if (is_file($baselinePath)) {
            $data = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
            $previousPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
            $previousPath = base_path("../baseline/reference_dump/cost-per-mt_{$previousPeriod}_mtd.json");
            $previous = is_file($previousPath)
                ? (json_decode(file_get_contents($previousPath), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [])
                : null;
            $previousStages = collect($previous['stages'] ?? [])->keyBy('stage');
            $data['comparison'] = collect($data['stages'] ?? [])->map(function (array $stage) use ($previousStages): array {
                $old = $previousStages->get($stage['stage']);
                $current = $stage['costPerMT'] ?? null;
                $prior = $old['costPerMT'] ?? null;
                return [
                    'stage' => $stage['stage'],
                    'currentCostPerMT' => $current,
                    'previousCostPerMT' => $prior,
                    'change' => $current === null || $prior === null ? null : $current - $prior,
                    'changePct' => $current === null || $prior === null || (float) $prior === 0.0 ? null : ($current - $prior) / abs($prior) * 100,
                ];
            })->values()->all();
            $data['comparisonPeriod'] = $previousPeriod;
            return $data;
        }

        $matrix = app(CostMatrixService::class)->build($period, $mode);
        $volume = (float) (MonthlyMetric::period($period)->key('coalGetting')->value('value') ?? 0);
        $gt = $matrix['grandTotal'];
        $stages = [
            ['stage'=>'Mining','valuationCost'=>$gt['valMining'],'operatingCost'=>$gt['costMining']],
            ['stage'=>'Coal Hauling','valuationCost'=>$gt['valHauling'],'operatingCost'=>$gt['costHauling']],
            ['stage'=>'Washing Plant','valuationCost'=>$gt['valWP'],'operatingCost'=>$gt['costWP']],
            ['stage'=>'BP PORT & Jetty','valuationCost'=>$gt['valPort'],'operatingCost'=>$gt['costPort']],
            ['stage'=>'General Admin','valuationCost'=>$gt['valNotValuated'],'operatingCost'=>$gt['costGA']],
            ['stage'=>'Selling Expense','valuationCost'=>'0','operatingCost'=>$gt['costSelling']],
        ];
        foreach ($stages as &$stage) { $stage['totalCost'] = (float)$stage['valuationCost'] + (float)$stage['operatingCost']; $stage['metricTons'] = $volume; $stage['costPerMT'] = $volume ? $stage['totalCost'] / $volume : null; $stage['costPerTotalMT'] = $stage['costPerMT']; }
        return ['currency'=>'USD','amountScale'=>1,'missingVolumePeriods'=>$volume?[]:[$period],'incompleteVolume'=>$volume===0.0,'volumeSource'=>'Google Sheets DB','totalVolumeProducedMT'=>$volume,'grandTotalCost'=>(float)$gt['allCost'],'overallCostPerMT'=>$volume?(float)$gt['allCost']/$volume:null,'stages'=>$stages];
    }
}
