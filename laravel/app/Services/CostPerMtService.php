<?php
namespace App\Services;
use App\Models\MonthlyMetric;
class CostPerMtService
{
    public function build(string $period, string $mode = 'mtd'): array
    {
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
