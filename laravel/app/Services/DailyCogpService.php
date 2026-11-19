<?php

namespace App\Services;

class DailyCogpService
{
    /**
     * Mirror the live 3000 daily COGP calculation using only synced Sheet rows.
     * Missing formula inputs make a day's estimate unavailable instead of zero.
     */
    public function calculate(iterable $productionRows, iterable $activityRows, iterable $accrualItems, iterable $parameters): array
    {
        $activitiesByDate = [];
        foreach ($activityRows as $activity) {
            $row = is_array($activity) ? $activity : (method_exists($activity, 'getAttributes') ? $activity->getAttributes() : (array) $activity);
            $date = substr((string) ($row['production_date'] ?? ''), 0, 10);
            if ($date === '') continue;
            $activitiesByDate[$date][] = $row;
        }

        $accrualRates = [];
        foreach ($accrualItems as $item) {
            $row = is_array($item) ? $item : (method_exists($item, 'getAttributes') ? $item->getAttributes() : (array) $item);
            if (strtoupper(trim((string) ($row['entity'] ?? ''))) !== 'HRS' || ($row['category'] ?? null) !== 'Mining') continue;
            $accrualRates[(string) ($row['period'] ?? '').'|'.(string) ($row['account_code'] ?? '')] = $this->nullableNumber($row['rate_usd'] ?? null);
        }

        $parameterValues = [];
        foreach ($parameters as $parameter) {
            $row = is_array($parameter) ? $parameter : (method_exists($parameter, 'getAttributes') ? $parameter->getAttributes() : (array) $parameter);
            $parameterValues[(string) ($row['period'] ?? '').'|'.(string) ($row['section'] ?? '').'|'.strtolower(trim((string) ($row['label'] ?? ''))).'|'.strtoupper(trim((string) ($row['entity'] ?? '')))] = $this->nullableNumber($row['value'] ?? null);
        }

        $results = [];
        foreach ($productionRows as $production) {
            $row = is_array($production) ? $production : (method_exists($production, 'getAttributes') ? $production->getAttributes() : (array) $production);
            $date = substr((string) ($row['production_date'] ?? ''), 0, 10);
            $coal = $this->nullableNumber($row['coal_getting_mt'] ?? null);
            if ($date === '' || $coal === null) continue;

            $dayActivities = $activitiesByDate[$date] ?? [];
            $measures = ['freedig'=>0.0,'blasting'=>0.0,'parting'=>0.0,'soil'=>0.0,'mud'=>0.0];
            $loading = 0.0;
            foreach ($dayActivities as $activity) {
                $activityName = strtolower(trim((string) ($activity['activity'] ?? '')));
                $metric = strtolower(trim((string) ($activity['metric'] ?? $activity['activity_type'] ?? '')));
                $value = $this->nullableNumber($activity['value'] ?? null) ?? 0.0;
                if ($activityName === 'ob removal' && array_key_exists($metric, $measures)) $measures[$metric] += $value;
                $contractor = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($activity['contractor'] ?? '')));
                if ($activityName === 'coal loading bua' && $contractor === 'PTHRS') $loading += $value;
            }

            $ob = array_sum($measures);
            $period = substr($date, 0, 7);
            $fuelPrice = $this->parameter($parameterValues, $period, 'monthly', 'fuel price including transport');
            $obBaseDistance = $this->parameter($parameterValues, $period, 'monthly', 'base hauling distance ob');
            $obActualDistance = $this->parameter($parameterValues, $period, 'monthly', 'ob hauling distance actual');
            $coalBaseDistance = $this->parameter($parameterValues, $period, 'monthly', 'base coal getting distance');
            $coalActualDistance = $this->parameter($parameterValues, $period, 'monthly', 'actual coal getting distance');
            $obOverdistance = $obBaseDistance === null || $obActualDistance === null ? null : (($obActualDistance - $obBaseDistance) / 1000) * 10;
            $coalOverdistance = $coalBaseDistance === null || $coalActualDistance === null ? null : (($coalActualDistance - $coalBaseDistance) / 1000) * 10;

            $fuelPairs = [
                ['ob removal include blasting', $measures['blasting'], 1.0],
                ['ob removal freedig', $measures['freedig'], 1.0],
                ['topsoil', $measures['soil'], 1.0],
                ['mud', $measures['mud'], 1.0],
                ['coal getting + hauling 1km', $coal, 1.0],
                ['ob overdistance', $measures['blasting'] + $measures['freedig'], $obOverdistance],
                ['coal hauling overdistance', $coal, $coalOverdistance],
            ];
            $fuel = 0.0;
            $missing = false;
            foreach ($fuelPairs as [$label, $volume, $multiplier]) {
                if (abs((float) $volume) < 0.0000001) continue;
                $fuelRate = $this->parameter($parameterValues, $period, 'fuel', $label, 'HRS');
                if ($multiplier === null || $fuelRate === null || $fuelPrice === null) {
                    $missing = true;
                    continue;
                }
                $fuel += $volume * $multiplier * $fuelRate * $fuelPrice;
            }

            $operation = 0.0;
            foreach ([['5000404', $ob], ['5000405', $coal], ['5000406', $coal]] as [$account, $volume]) {
                if (abs((float) $volume) < 0.0000001) continue;
                $rate = $accrualRates[$period.'|'.$account] ?? null;
                if ($rate === null) { $missing = true; continue; }
                $operation += $volume * $rate;
            }

            if ($loading > 0) {
                $loadingRates = [];
                foreach (['Coal Hauling BUA to Port','Coal Hauling BUA to WP'] as $category) {
                    foreach ($accrualItems as $item) {
                        $item = is_array($item) ? $item : (method_exists($item, 'getAttributes') ? $item->getAttributes() : (array) $item);
                        if (($item['period'] ?? null) === $period && (string) ($item['account_code'] ?? '') === '5000411'
                            && strtoupper(trim((string) ($item['entity'] ?? ''))) === 'HRS' && ($item['category'] ?? null) === $category) {
                            $loadingRates[$category] = $this->nullableNumber($item['rate_usd'] ?? null);
                            break;
                        }
                    }
                }
                $operationRates = [
                    $this->parameter($parameterValues, $period, 'operation', 'coal loading at rom to port', 'HRS'),
                    $this->parameter($parameterValues, $period, 'operation', 'coal loading at rom to wp', 'HRS'),
                ];
                $fuelRates = [
                    $this->parameter($parameterValues, $period, 'fuel', 'coal loading at rom to port', 'HRS'),
                    $this->parameter($parameterValues, $period, 'fuel', 'coal loading at rom to wp', 'HRS'),
                ];
                $allRates = [...array_values($loadingRates), ...$operationRates, ...$fuelRates];
                if (count($loadingRates) === 2 && !in_array(null, $allRates, true)
                    && abs($loadingRates['Coal Hauling BUA to Port'] - $loadingRates['Coal Hauling BUA to WP']) < 1e-9
                    && abs($operationRates[0] - $operationRates[1]) < 1e-9
                    && abs($fuelRates[0] - $fuelRates[1]) < 1e-9 && $fuelPrice !== null) {
                    $operation += $loading * $loadingRates['Coal Hauling BUA to Port'];
                    $fuel += $loading * $fuelRates[0] * $fuelPrice;
                } else {
                    $missing = true;
                }
            }

            $results[$date] = [
                'cogp'=>$missing ? null : $operation + $fuel,
                'operation'=>$operation,
                'fuel'=>$missing ? null : $fuel,
                'missing'=>$missing,
            ];
        }

        return $results;
    }

    /** Daily contractor hauling cost, using only synced Production quantities and DB rates. */
    public function calculateContractorHauling(iterable $activityRows, iterable $accrualItems): array
    {
        $rates = [];
        foreach ($accrualItems as $item) {
            $row = is_array($item) ? $item : (method_exists($item, 'getAttributes') ? $item->getAttributes() : (array) $item);
            $category = (string) ($row['category'] ?? '');
            $code = (string) ($row['account_code'] ?? '');
            $entity = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($row['entity'] ?? '')));
            if (!in_array($code, ['5000407','5000408','5000409'], true) || !in_array($entity, ['HRS','SMA'], true)) continue;
            $rates[(string) ($row['period'] ?? '').'|'.$category.'|'.$entity] = $this->nullableNumber($row['rate_usd'] ?? null);
        }

        $routeNames = [
            'bua to port' => 'Coal Hauling BUA to Port',
            'bua to washing plant' => 'Coal Hauling BUA to WP',
            'washing plant to port' => 'Coal Hauling WP to Port',
        ];
        $result = [];
        foreach ($activityRows as $activity) {
            $row = is_array($activity) ? $activity : (method_exists($activity, 'getAttributes') ? $activity->getAttributes() : (array) $activity);
            if (strcasecmp((string) ($row['activity'] ?? ''), 'Coal Hauling') !== 0 || strcasecmp((string) ($row['metric'] ?? ''), 'quantity') !== 0) continue;
            $date = substr((string) ($row['production_date'] ?? ''), 0, 10);
            if ($date === '') continue;
            $result[$date] ??= ['amount'=>0.0,'missing'=>[],'hasVolume'=>false];
            $qty = $this->nullableNumber($row['value'] ?? null);
            if ($qty === null) continue;
            $routeKey = strtolower(trim((string) ($row['route'] ?? '')));
            $category = $routeNames[$routeKey] ?? null;
            $contractor = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($row['contractor'] ?? '')));
            if (str_contains($contractor, 'BP')) continue;
            $result[$date]['hasVolume'] = true;
            if (abs($qty) < 0.0000001) continue;
            $entity = str_contains($contractor, 'HRS') ? 'HRS' : (str_contains($contractor, 'SMA') ? 'SMA' : null);
            $rate = $category && $entity ? ($rates[substr($date, 0, 7).'|'.$category.'|'.$entity] ?? null) : null;
            if ($rate === null) {
                $result[$date]['missing'][] = ($entity ?? 'Unknown contractor').' '.($category ?? (string) ($row['route'] ?? 'route')).': effective rate unavailable';
                continue;
            }
            $result[$date]['amount'] += $qty * $rate;
        }
        foreach ($result as &$row) {
            if (!$row['hasVolume']) $row['amount'] = null;
            elseif ($row['missing']) $row['amount'] = null;
            unset($row['hasVolume']);
        }
        unset($row);
        return $result;
    }

    private function parameter(array $values, string $period, string $section, string $label, string $entity = ''): ?float
    {
        return $values[$period.'|'.$section.'|'.strtolower($label).'|'.strtoupper($entity)] ?? null;
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-') return null;
        return is_numeric($value) ? (float) $value : null;
    }
}
