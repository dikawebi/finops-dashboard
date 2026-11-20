<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EquipmentCostService
{
    public function build(string $period, string $mode): array
    {
        $config = config('services.google_sheets');
        $credentialPath = $config['credentials_path'] ?? null;
        if (!($config['enabled'] ?? false) || !$credentialPath || !is_file($credentialPath)) {
            throw new RuntimeException('Google Sheets belum dikonfigurasi untuk Equipment Cost.');
        }
        $credentialStamp = (string) filemtime($credentialPath);
        $credentials = json_decode(file_get_contents($credentialPath), true, 512, JSON_THROW_ON_ERROR);
        $token = Cache::remember('google_sheets.equipment_token.'.hash('sha256', $credentialStamp.$credentials['client_email']), 3400, fn () => $this->accessToken($credentials));
        $spreadsheet = $config['spreadsheet_id'];
        $baseSheet = $config['equipment_sheet_name'] ?? 'Inhouse Mining';
        $dbSheet = $config['equipment_db_sheet_name'] ?? 'DB';
        $tickets = $this->readValues($token, $spreadsheet, $baseSheet, 'A1:T1000');
        $dbRows = $this->readValues($token, $spreadsheet, $dbSheet, 'A1:W1000');

        $inventoryPath = $config['equipment_inventory_path'] ?? base_path('../data/equipment_inventory.json');
        $livePeriod = $period >= '2026-10';
        // Keep the approved legacy baseline only through September. October onward
        // must come from posted D365 movement lines, never from the imported snapshot.
        $inventoryAvailable = is_file($inventoryPath);
        $inventoryRows = $inventoryAvailable ? $this->inventoryRows(json_decode(file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR)) : [];
        $start = $mode === 'ytd' ? $period.'-01' : $period.'-01';
        $end = (new \DateTimeImmutable($period.'-01'))->modify('last day of this month')->format('Y-m-d');
        $yearStart = substr($period, 0, 4).'-01-01';
        $from = $mode === 'ytd' ? $yearStart : $start;
        $d365From = max($from, '2026-10-01');
        $d365InventoryRows = $livePeriod ? DB::table('d365_typed_records')->where('entity_key', 'inventory_movement_entries')->where('company', 'bp')->whereBetween('transaction_date', [$d365From, $end])->where('is_posted', true)->get(['transaction_date', 'asset', 'item_number', 'cost_amount', 'quantity', 'dimensions', 'payload']) : collect();
        $d365InventoryCount = $livePeriod ? $d365InventoryRows->count() : null;

        $inventoryByActivity = $inventoryByUnit = $fuelLitres = [];
        foreach ($inventoryRows as $raw) {
            $row = $this->normalizeRow($raw);
            $date = $this->date($row['financial_date'] ?? $row['financialdate'] ?? $row['physical_date'] ?? $row['physicaldate'] ?? null);
            if (!$date || $date < $from || $date > $end) continue;
            if ($date >= '2026-10-01') continue;
            $activity = $this->canonicalActivity(trim((string) ($row['aktivitas'] ?? $row['activity'] ?? '')) ?: '(Unassigned)');
            $unit = $this->unitCode($row['asset'] ?? $row['unit'] ?? '');
            $itemGroup = trim((string) ($row['item_group'] ?? $row['itemgroup'] ?? '')) ?: '(Unassigned)';
            $cost = -($this->number($row['cost_amount'] ?? $row['costamount'] ?? 0) ?? 0);
            $quantity = -($this->number($row['quantity'] ?? 0) ?? 0);
            $month = substr($date, 0, 7);
            $this->addInventory($inventoryByActivity, $activity, $itemGroup, $cost, $quantity);
            if ($unit !== '') {
                $this->addInventory($inventoryByUnit, $activity.'|'.$unit, $itemGroup, $cost, $quantity);
                $inventoryByUnit[$activity.'|'.$unit]['activity'] = $activity;
                $inventoryByUnit[$activity.'|'.$unit]['unit'] = $unit;
            }
            $groupNorm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $itemGroup));
            $unitNorm = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($row['unit'] ?? '')));
            if (str_contains($groupNorm, 'FUEL') && in_array($unitNorm, ['L', 'LTR', 'LITRE', 'LITER', 'LITRES', 'LITERS'], true)) {
                if (!isset($fuelLitres[$activity.'|'.$unit.'|'.$month])) $fuelLitres[$activity.'|'.$unit.'|'.$month] = 0.0;
                $fuelLitres[$activity.'|'.$unit.'|'.$month] += $quantity;
            }
        }

        $monthlyProduction = $ticketsByActivity = $units = $productionActivitiesByUnitMonth = [];
        foreach ($tickets as $index => $row) {
            if ($index === 0) continue;
            $date = $this->date($row[1] ?? null);
            if ($date && $date >= $from && $date <= $end) {
                $loadingActivity = $this->canonicalActivity('Coal Loading at WP to Port');
                foreach (range(15, 19) as $column) {
                    $loadingMT = $this->number($row[$column] ?? null);
                    $equipment = $this->unitCode($tickets[1][$column] ?? '');
                    if ($loadingMT === null || $loadingMT <= 0 || $equipment === '') continue;
                    $monthlyProduction[$loadingActivity] = ($monthlyProduction[$loadingActivity] ?? 0) + $loadingMT;
                    $productionActivitiesByUnitMonth[substr($date, 0, 7).'|'.$equipment][$loadingActivity] = true;
                    $equipmentKey = $loadingActivity.'|'.$equipment;
                    $units[$equipmentKey] ??= ['activity'=>$loadingActivity,'unit'=>$equipment,'haulingMT'=>0.0,'loaderMT'=>0.0];
                    $units[$equipmentKey]['loaderMT'] += $loadingMT;
                }
            }
            $netto = $this->number($row[8] ?? null);
            $haulingUnit = $this->unitCode($row[3] ?? '');
            $loaderUnit = $this->unitCode($row[4] ?? '');
            $transporter = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($row[11] ?? '')));
            $activity = $this->canonicalActivity(trim((string) ($row[12] ?? '')));
            if (!$date || $date < $from || $date > $end || $netto === null || !$haulingUnit || !$activity || !str_starts_with($transporter, 'PTBP')) continue;
            $mt = $netto / 1000;
            $month = substr($date, 0, 7);
            $monthlyProduction[$activity] = ($monthlyProduction[$activity] ?? 0) + $mt;
            $ticketsByActivity[$activity][] = ['haulingUnit' => $haulingUnit, 'loaderUnit' => $loaderUnit, 'mt' => $mt, 'month' => $month];
            foreach (array_unique(array_filter([$haulingUnit, $loaderUnit])) as $productionUnit) $productionActivitiesByUnitMonth[$month.'|'.$productionUnit][$activity] = true;
            foreach ([$haulingUnit => 'hauling', $loaderUnit => 'loader'] as $unit => $role) {
                if ($unit === '') continue;
                $key = $activity.'|'.$unit;
                $units[$key] ??= ['activity' => $activity, 'unit' => $unit, 'haulingMT' => 0.0, 'loaderMT' => 0.0];
                $units[$key][$role.'MT'] += $mt;
            }
        }

        $d365ActivityStats = [];
        $d365MappingRows = [];
        $d365MappedByProduction = 0;
        $d365MappedByCrosswalk = 0;
        $d365UnmappedCount = 0;
        $d365UnmappedCost = 0.0;
        if ($livePeriod && $d365InventoryCount > 0) {
            $dimensionNames = $this->d365ActivityNames();
            $savedMappings = DB::table('equipment_activity_mappings')->pluck('target_activity', 'activity_code')->all();
            $knownActivities = $this->equipmentActivities();
            foreach ($d365InventoryRows as $d365Row) {
                $payload = json_decode((string) $d365Row->payload, true) ?: [];
                $dimensions = json_decode((string) $d365Row->dimensions, true) ?: [];
                $activityCode = $this->activityDimensionCode((string) ($dimensions['defaultLedgerDimensionDisplayValue'] ?? $payload['DefaultLedgerDimensionDisplayValue'] ?? ''));
                $activityDescription = $activityCode ? ($dimensionNames[$activityCode] ?? 'Unknown D365 Activity') : 'Activity dimension unavailable';
                $savedTarget = $activityCode ? ($savedMappings[$activityCode] ?? null) : null;
                $unit = $this->unitCode($d365Row->asset ?? '');
                $date = $this->date($d365Row->transaction_date ?? null);
                if (!$date) continue;
                $month = substr($date, 0, 7);
                $candidates = $unit ? array_keys($productionActivitiesByUnitMonth[$month.'|'.$unit] ?? []) : [];
                $activity = 'Unmapped';
                $mappingMethod = 'unmapped';
                if (count($candidates) === 1 && in_array($candidates[0], $knownActivities, true)) {
                    $activity = $candidates[0];
                    $mappingMethod = 'production_unit_month';
                    $d365MappedByProduction++;
                } elseif ($savedTarget && in_array($savedTarget, $knownActivities, true)) {
                    $activity = $savedTarget;
                    $mappingMethod = 'activity_crosswalk';
                    $d365MappedByCrosswalk++;
                } else {
                    $d365UnmappedCount++;
                }

                $cost = $this->number($d365Row->cost_amount ?? $payload['CostAmount'] ?? null) ?? 0.0;
                // Inventory issue quantity is shown as positive consumption, as in 3000.
                // CostAmount intentionally keeps D365's original sign per user decision.
                $quantity = -($this->number($d365Row->quantity ?? $payload['InventoryQuantity'] ?? null) ?? 0.0);
                $itemNumber = trim((string) ($d365Row->item_number ?? $payload['ItemNumber'] ?? ''));
                $itemLabel = $itemNumber !== '' ? $itemNumber : '(No item number)';
                $this->addInventory($inventoryByActivity, $activity, $itemLabel, $cost, $quantity);
                $displayUnit = $unit !== '' ? $unit : '(No Asset)';
                $this->addInventory($inventoryByUnit, $activity.'|'.$displayUnit, $itemLabel, $cost, $quantity);
                $inventoryByUnit[$activity.'|'.$displayUnit]['activity'] = $activity;
                $inventoryByUnit[$activity.'|'.$displayUnit]['unit'] = $displayUnit;

                $activityKey = $activityCode ?: '(No activity code)';
                $d365ActivityStats[$activityKey] ??= ['activityCode' => $activityCode, 'description' => $activityDescription, 'transactionCount' => 0, 'costAmount' => 0.0, 'mappedByProduction' => 0, 'mappedByCrosswalk' => 0, 'unmapped' => 0, 'targetActivity' => $savedTarget];
                $d365ActivityStats[$activityKey]['transactionCount']++;
                $d365ActivityStats[$activityKey]['costAmount'] += $cost;
                if ($mappingMethod === 'production_unit_month') $d365ActivityStats[$activityKey]['mappedByProduction']++;
                elseif ($mappingMethod === 'activity_crosswalk') $d365ActivityStats[$activityKey]['mappedByCrosswalk']++;
                else $d365ActivityStats[$activityKey]['unmapped']++;
                if ($mappingMethod === 'unmapped') $d365UnmappedCost += $cost;
            }
            $d365MappingRows = array_values($d365ActivityStats);
            usort($d365MappingRows, fn (array $a, array $b): int => $b['transactionCount'] <=> $a['transactionCount']);
        }

        $dbYear = null;
        if (preg_match('/(20\d{2})/', (string) ($dbRows[1][6] ?? ''), $yearMatch)) $dbYear = (int) $yearMatch[1];
        $dbPeriodAvailable = $dbYear === (int) substr($period, 0, 4);
        $fuelPrices = $dbPeriodAvailable ? $this->fuelPrices($dbRows, (int) substr($period, 0, 4)) : [];
        $employeeCosts = $dbPeriodAvailable ? $this->employeeCosts($dbRows, $period, $mode) : [];
        $inventoryValuesAvailable = $livePeriod ? $d365InventoryCount > 0 : $inventoryAvailable;
        $currencyLabel = $livePeriod ? 'D365 amount' : 'USD';
        $activityNames = array_unique(array_merge($this->equipmentActivities(), array_keys($monthlyProduction), array_keys($inventoryByActivity), array_keys($employeeCosts)));
        $activities = [];
        foreach ($activityNames as $activity) {
            $productionMT = (float) ($monthlyProduction[$activity] ?? 0);
            $inventoryCost = (float) ($inventoryByActivity[$activity]['costUSD'] ?? 0);
            $employee = $employeeCosts[$activity] ?? ['value' => null, 'complete' => false];
            $unitNames = [];
            foreach ($units as $unit) if ($unit['activity'] === $activity) $unitNames[$unit['unit']] = true;
            foreach ($inventoryByUnit as $inventory) if (($inventory['activity'] ?? null) === $activity && filled($inventory['unit'] ?? null) && $inventory['unit'] !== '(No Asset)') $unitNames[$inventory['unit']] = true;
            $employeeForTotal = $livePeriod ? 0.0 : (float) ($employee['value'] ?? 0);
            $activities[] = [
                'activity' => $activity,
                'productionMT' => $productionMT > 0 ? $productionMT : null,
                'unitCount' => count($unitNames),
                'inventoryCostAmount' => $inventoryValuesAvailable ? $inventoryCost : null,
                'employeeCostUSD' => $employee['value'],
                'employeeCostComplete' => $employee['complete'],
                'totalCostAmount' => $inventoryValuesAvailable ? $inventoryCost + $employeeForTotal : null,
                'inventoryCostPerMT' => $inventoryValuesAvailable && $productionMT > 0 ? $inventoryCost / $productionMT : null,
                'itemGroups' => $this->chartGroups($this->groupsWithEmployeeCost($inventoryByActivity[$activity]['groups'] ?? [], $livePeriod ? null : $employee['value']), !$livePeriod),
                'hasInventoryCost' => isset($inventoryByActivity[$activity]),
                'hasProduction' => $productionMT > 0,
            ];
        }

        $unitRows = [];
        foreach ($units as $key => $unit) {
            $inventory = $inventoryByUnit[$key] ?? ['costUSD' => 0, 'groups' => []];
            $fuel = 0.0; $fuelEstimate = 0.0; $estimateComplete = true; $hasFuel = false;
            foreach ($fuelLitres as $fuelKey => $litres) {
                [$fuelActivity, $fuelUnit, $fuelMonth] = explode('|', $fuelKey);
                if ($fuelActivity !== $unit['activity'] || $fuelUnit !== $unit['unit']) continue;
                $hasFuel = true;
                $fuel += $litres;
                if (!array_key_exists($fuelMonth, $fuelPrices)) $estimateComplete = false;
                else $fuelEstimate += $litres * $fuelPrices[$fuelMonth];
            }
            $unitRows[] = [
                'activity' => $unit['activity'], 'unit' => $unit['unit'], 'haulingMT' => $unit['haulingMT'], 'loaderMT' => $unit['loaderMT'],
                'fuelLitres' => $livePeriod ? null : ($hasFuel ? $fuel : null),
                'fuelPerHaulingMT' => $unit['haulingMT'] > 0 && $hasFuel ? $fuel / $unit['haulingMT'] : null,
                'fuelPerLoaderMT' => $unit['loaderMT'] > 0 && $hasFuel ? $fuel / $unit['loaderMT'] : null,
                'fuelCostEstimateUSD' => !$livePeriod && $hasFuel && $estimateComplete ? $fuelEstimate : null,
                'otherInventoryCostAmount' => $inventoryValuesAvailable ? $inventory['costUSD'] : null,
                'totalInventoryCostAmount' => $inventoryValuesAvailable ? $inventory['costUSD'] : null,
                'itemGroups' => $this->chartGroups($inventory['groups'] ?? [], !$livePeriod),
            ];
        }
        foreach ($inventoryByUnit as $key => $inventory) {
            if (!isset($units[$key])) $unitRows[] = ['activity'=>$inventory['activity'],'unit'=>$inventory['unit'],'haulingMT'=>null,'loaderMT'=>null,'fuelLitres'=>null,'fuelPerHaulingMT'=>null,'fuelPerLoaderMT'=>null,'fuelCostEstimateUSD'=>null,'otherInventoryCostAmount'=>$inventory['costUSD'],'totalInventoryCostAmount'=>$inventory['costUSD'],'itemGroups'=>$this->chartGroups($inventory['groups'] ?? [], !$livePeriod)];
        }
        usort($unitRows, fn ($a, $b) => [$a['activity'], $a['unit']] <=> [$b['activity'], $b['unit']]);

        return [
            'period' => $period, 'mode' => $mode, 'currency' => $livePeriod ? null : 'USD', 'currencyLabel' => $currencyLabel, 'activities' => $activities, 'units' => $unitRows,
            'activityMappings' => $d365MappingRows,
            'unmapped' => ['transactionCount'=>$d365UnmappedCount, 'costAmount'=>$d365UnmappedCost],
            'sources' => ['inventory'=>$livePeriod ? ['available'=>$d365InventoryCount > 0,'source'=>'D365 Inventory Movement Journal Entries (Posted)','transactionCount'=>$d365InventoryCount,'activityMappingAvailable'=>($d365MappedByProduction + $d365MappedByCrosswalk) > 0,'mappedByProduction'=>$d365MappedByProduction,'mappedByCrosswalk'=>$d365MappedByCrosswalk,'unmappedCount'=>$d365UnmappedCount,'currencyCodeAvailable'=>false] : ['available'=>$inventoryAvailable,'path'=>$inventoryAvailable ? basename($inventoryPath) : null], 'production'=>'Google Sheets · Inhouse Mining', 'employeeAndFuel'=>['source'=>'Google Sheets · DB','available'=>$dbPeriodAvailable,'year'=>$dbYear]],
            'notes' => array_values(array_filter([
                $livePeriod ? ($d365InventoryCount > 0 ? "Equipment Cost uses {$d365InventoryCount} posted D365 movement rows. CostAmount retains its original D365 sign; production unit-month match takes precedence, then the reviewed Activity crosswalk. {$d365UnmappedCount} transactions remain Unmapped. D365 entity does not expose CurrencyCode, so amounts are labeled D365 CostAmount and are not combined with USD employee cost." : 'No posted D365 Inventory Movement Journal Entries are staged for this period. Equipment Cost does not use the legacy inventory snapshot after September 2026.') : ($inventoryAvailable ? null : 'Inventory transaction snapshot is missing. Production and DB references loaded, but inventory cost and item group values are withheld until data/equipment_inventory.json is provided.'),
                $dbPeriodAvailable ? null : 'Fuel price and Employee Cost are unavailable because the DB sheet year does not match the selected period.',
            ])),
        ];
    }

    private function readValues(string $token, string $spreadsheet, string $sheet, string $range): array
    {
        $range = "'".str_replace("'", "''", $sheet)."'!{$range}";
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet).'/values/'.rawurlencode($range);
        $response = Http::withToken($token)->timeout(60)->get($url, ['valueRenderOption'=>'UNFORMATTED_VALUE']);
        if ($response->failed()) throw new RuntimeException("Google Sheets gagal membaca {$sheet}: HTTP ".$response->status().'.');
        return $response->json('values', []);
    }

    private function accessToken(array $credentials): string
    {
        $now = time(); $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $unsigned = $encode(['alg'=>'RS256','typ'=>'JWT']).'.'.$encode(['iss'=>$credentials['client_email'],'scope'=>'https://www.googleapis.com/auth/spreadsheets.readonly','aud'=>$credentials['token_uri'],'iat'=>$now,'exp'=>$now+3600]);
        $key = openssl_pkey_get_private($credentials['private_key']);
        if (!$key || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Tidak dapat menandatangani token Google Service Account.');
        $response = Http::asForm()->timeout(30)->post($credentials['token_uri'], ['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=')]);
        if ($response->failed()) throw new RuntimeException('Autentikasi Google gagal: HTTP '.$response->status().'.');
        return (string) $response->json('access_token');
    }

    private function inventoryRows(mixed $payload): array
    {
        if (!is_array($payload)) return [];
        foreach (['rows', 'transactions', 'data'] as $key) if (isset($payload[$key]) && is_array($payload[$key])) return array_values($payload[$key]);
        return array_is_list($payload) ? $payload : [];
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) $normalized[strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key))] = $value;
        return $normalized;
    }

    private function addInventory(array &$target, string $key, string $itemGroup, float $cost, float $quantity): void
    {
        $target[$key] ??= ['costUSD'=>0.0,'quantity'=>0.0,'groups'=>[]];
        $target[$key]['costUSD'] += $cost;
        $target[$key]['quantity'] += $quantity;
        $target[$key]['groups'][$itemGroup] = ($target[$key]['groups'][$itemGroup] ?? 0) + $cost;
    }

    private function chartGroups(array $groups, bool $negativeMeansCredit = true): array
    {
        $absoluteTotal = array_sum(array_map('abs', $groups));
        $result = [];
        foreach ($groups as $label => $value) $result[] = ['label'=>$label,'value'=>$value,'percentage'=>$absoluteTotal > 0 ? abs($value) / $absoluteTotal * 100 : 0,'credit'=>$negativeMeansCredit && $value < 0];
        usort($result, fn ($a, $b) => abs($b['value']) <=> abs($a['value']));
        return $result;
    }

    private function equipmentActivities(): array
    {
        return ['Coal Hauling BUA to Port','Coal Hauling BUA to WP','Coal Hauling WP to Port','Coal Loading BUA to Port','Coal Loading BUA to WP','Coal Loading WP to Port'];
    }

    private function d365ActivityNames(): array
    {
        $names = [];
        foreach (DB::table('d365_typed_records')->where('entity_key', 'dimension_values')->get(['payload']) as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            if (($payload['FinancialDimension'] ?? null) !== 'Activity') continue;
            $code = trim((string) ($payload['DimensionValue'] ?? ''));
            if ($code !== '') $names[$code] = trim((string) ($payload['Description'] ?? ''));
        }
        return $names;
    }

    private function activityDimensionCode(string $displayValue): ?string
    {
        preg_match_all('/(?<![0-9])(4[0-9]{6})(?![0-9])/', $displayValue, $matches);
        $codes = array_values(array_unique($matches[1] ?? []));
        return count($codes) === 1 ? $codes[0] : null;
    }

    private function canonicalActivity(string $activity): string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $activity));
        $aliases = [
            'coalhaulingrombuatport'=>'Coal Hauling BUA to Port', 'coalhaulingbuatport'=>'Coal Hauling BUA to Port',
            'coalhaulingrombuatwp'=>'Coal Hauling BUA to WP', 'coalhaulingbuatwp'=>'Coal Hauling BUA to WP',
            'coalhaulingwptoport'=>'Coal Hauling WP to Port',
            'coalloadingatromtoport'=>'Coal Loading BUA to Port', 'coalloadingbuatport'=>'Coal Loading BUA to Port',
            'coalloadingatromtowp'=>'Coal Loading BUA to WP', 'coalloadingbuatwp'=>'Coal Loading BUA to WP',
            'coalloadingatwptoport'=>'Coal Loading WP to Port', 'coalloadingwptoport'=>'Coal Loading WP to Port',
        ];
        return $aliases[$key] ?? $activity;
    }

    private function groupsWithEmployeeCost(array $groups, ?float $employeeCost): array
    {
        if ($employeeCost !== null) $groups['EMPLOYEE COST (DB)'] = ($groups['EMPLOYEE COST (DB)'] ?? 0) + $employeeCost;
        return $groups;
    }

    private function fuelPrices(array $rows, int $year): array
    {
        $months = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
        foreach ($rows as $row) {
            $label = strtolower((string) ($row[3] ?? ''));
            $unit = strtolower((string) ($row[4] ?? ''));
            if ($label !== 'fuel price including transport' || !str_contains($unit, 'us$/litre')) continue;
            $prices = [];
            foreach ($months as $index => $month) {
                $value = $this->number($row[8 + $index] ?? null);
                if ($value !== null) $prices[sprintf('%04d-%02d', $year, $index + 1)] = $value;
            }
            return $prices;
        }
        return [];
    }

    private function employeeCosts(array $rows, string $period, string $mode): array
    {
        $results = [];
        $year = substr($period, 0, 4); $lastMonth = (int) substr($period, 5, 2);
        foreach ($rows as $row) {
            $label = strtolower(trim((string) ($row[3] ?? '')));
            $currency = strtoupper(trim((string) ($row[4] ?? '')));
            $entity = strtoupper(trim((string) ($row[5] ?? '')));
            $activity = $this->canonicalActivity(trim((string) ($row[22] ?? '')));
            if ($label !== 'employee cost' || $currency !== 'USD' || $entity !== 'BP' || !$activity) continue;
            if (!in_array($activity, $this->equipmentActivities(), true)) continue;
            $amounts = []; $complete = true;
            $first = $mode === 'ytd' ? 1 : $lastMonth; $last = $lastMonth;
            for ($month = $first; $month <= $last; $month++) {
                $value = $this->number($row[7 + $month] ?? null);
                if ($value === null) { $complete = false; continue; }
                $amounts[] = $value;
            }
            if (!$amounts) { $results[$activity] = ['value'=>null,'complete'=>false]; continue; }
            $results[$activity] = ['value'=>array_sum($amounts),'complete'=>$complete && count($amounts) === $last - $first + 1];
        }
        return $results;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return gmdate('Y-m-d', (int) (($value - 25569) * 86400));
        foreach (['!m/d/Y', '!n/j/Y', '!d M Y', '!j M Y', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, (string) $value);
            if ($date) return $date->format('Y-m-d');
        }
        $time = strtotime((string) $value);
        return $time ? date('Y-m-d', $time) : null;
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-' || $value === '—') return null;
        if (is_numeric($value)) return (float) $value;
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $value));
        return $clean !== '' && is_numeric($clean) ? (float) $clean : null;
    }

    private function unitCode(mixed $value): string { return strtoupper(preg_replace('/\s+/', '', trim((string) $value))); }
}
