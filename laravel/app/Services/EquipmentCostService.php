<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
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
        $inventoryAvailable = is_file($inventoryPath);
        $inventoryRows = $inventoryAvailable ? $this->inventoryRows(json_decode(file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR)) : [];
        $start = $mode === 'ytd' ? $period.'-01' : $period.'-01';
        $end = (new \DateTimeImmutable($period.'-01'))->modify('last day of this month')->format('Y-m-d');
        $yearStart = substr($period, 0, 4).'-01-01';
        $from = $mode === 'ytd' ? $yearStart : $start;

        $inventoryByActivity = $inventoryByUnit = $fuelLitres = [];
        foreach ($inventoryRows as $raw) {
            $row = $this->normalizeRow($raw);
            $date = $this->date($row['financial_date'] ?? $row['financialdate'] ?? $row['physical_date'] ?? $row['physicaldate'] ?? null);
            if (!$date || $date < $from || $date > $end) continue;
            $activity = trim((string) ($row['aktivitas'] ?? $row['activity'] ?? '')) ?: '(Unassigned)';
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

        $monthlyProduction = $ticketsByActivity = $units = [];
        foreach ($tickets as $index => $row) {
            if ($index === 0) continue;
            $date = $this->date($row[1] ?? null);
            if ($date && $date >= $from && $date <= $end) {
                $loadingActivity = 'Coal Loading at WP to Port';
                foreach (range(15, 19) as $column) {
                    $loadingMT = $this->number($row[$column] ?? null);
                    $equipment = $this->unitCode($tickets[1][$column] ?? '');
                    if ($loadingMT === null || $loadingMT <= 0 || $equipment === '') continue;
                    $monthlyProduction[$loadingActivity] = ($monthlyProduction[$loadingActivity] ?? 0) + $loadingMT;
                    $equipmentKey = $loadingActivity.'|'.$equipment;
                    $units[$equipmentKey] ??= ['activity'=>$loadingActivity,'unit'=>$equipment,'haulingMT'=>0.0,'loaderMT'=>0.0];
                    $units[$equipmentKey]['loaderMT'] += $loadingMT;
                }
            }
            $netto = $this->number($row[8] ?? null);
            $haulingUnit = $this->unitCode($row[3] ?? '');
            $loaderUnit = $this->unitCode($row[4] ?? '');
            $transporter = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($row[11] ?? '')));
            $activity = trim((string) ($row[12] ?? ''));
            if (!$date || $date < $from || $date > $end || $netto === null || !$haulingUnit || !$activity || !str_starts_with($transporter, 'PTBP')) continue;
            $mt = $netto / 1000;
            $month = substr($date, 0, 7);
            $monthlyProduction[$activity] = ($monthlyProduction[$activity] ?? 0) + $mt;
            $ticketsByActivity[$activity][] = ['haulingUnit' => $haulingUnit, 'loaderUnit' => $loaderUnit, 'mt' => $mt, 'month' => $month];
            foreach ([$haulingUnit => 'hauling', $loaderUnit => 'loader'] as $unit => $role) {
                if ($unit === '') continue;
                $key = $activity.'|'.$unit;
                $units[$key] ??= ['activity' => $activity, 'unit' => $unit, 'haulingMT' => 0.0, 'loaderMT' => 0.0];
                $units[$key][$role.'MT'] += $mt;
            }
        }

        $dbYear = null;
        if (preg_match('/(20\d{2})/', (string) ($dbRows[1][6] ?? ''), $yearMatch)) $dbYear = (int) $yearMatch[1];
        $dbPeriodAvailable = $dbYear === (int) substr($period, 0, 4);
        $fuelPrices = $dbPeriodAvailable ? $this->fuelPrices($dbRows, (int) substr($period, 0, 4)) : [];
        $employeeCosts = $dbPeriodAvailable ? $this->employeeCosts($dbRows, $period, $mode) : [];
        $activityNames = array_unique(array_merge(array_keys($monthlyProduction), array_keys($inventoryByActivity), array_keys($employeeCosts)));
        sort($activityNames, SORT_NATURAL | SORT_FLAG_CASE);
        $activities = [];
        foreach ($activityNames as $activity) {
            $productionMT = (float) ($monthlyProduction[$activity] ?? 0);
            $inventoryCost = (float) ($inventoryByActivity[$activity]['costUSD'] ?? 0);
            $employee = $employeeCosts[$activity] ?? ['value' => null, 'complete' => false];
            $activities[] = [
                'activity' => $activity,
                'productionMT' => $productionMT > 0 ? $productionMT : null,
                'unitCount' => count(array_filter($units, fn ($unit) => $unit['activity'] === $activity)),
                'inventoryCostUSD' => $inventoryAvailable ? $inventoryCost : null,
                'employeeCostUSD' => $employee['value'],
                'employeeCostComplete' => $employee['complete'],
                'totalCostUSD' => $inventoryAvailable ? $inventoryCost + (float) ($employee['value'] ?? 0) : null,
                'inventoryCostPerMT' => $inventoryAvailable && $productionMT > 0 ? $inventoryCost / $productionMT : null,
                'itemGroups' => $this->chartGroups($inventoryByActivity[$activity]['groups'] ?? []),
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
                'fuelLitres' => $hasFuel ? $fuel : null,
                'fuelPerHaulingMT' => $unit['haulingMT'] > 0 && $hasFuel ? $fuel / $unit['haulingMT'] : null,
                'fuelPerLoaderMT' => $unit['loaderMT'] > 0 && $hasFuel ? $fuel / $unit['loaderMT'] : null,
                'fuelCostEstimateUSD' => $hasFuel && $estimateComplete ? $fuelEstimate : null,
                'otherInventoryCostUSD' => $inventoryAvailable ? $inventory['costUSD'] : null,
                'totalInventoryCostUSD' => $inventoryAvailable ? $inventory['costUSD'] : null,
                'itemGroups' => $inventory['groups'] ?? [],
            ];
        }
        foreach ($inventoryByUnit as $key => $inventory) {
            if (!isset($units[$key])) $unitRows[] = ['activity'=>$inventory['activity'],'unit'=>$inventory['unit'],'haulingMT'=>null,'loaderMT'=>null,'fuelLitres'=>null,'fuelPerHaulingMT'=>null,'fuelPerLoaderMT'=>null,'fuelCostEstimateUSD'=>null,'otherInventoryCostUSD'=>$inventory['costUSD'],'totalInventoryCostUSD'=>$inventory['costUSD'],'itemGroups'=>$inventory['groups']];
        }
        usort($unitRows, fn ($a, $b) => [$a['activity'], $a['unit']] <=> [$b['activity'], $b['unit']]);

        return [
            'period' => $period, 'mode' => $mode, 'currency' => 'USD', 'activities' => $activities, 'units' => $unitRows,
            'sources' => ['inventory'=>['available'=>$inventoryAvailable,'path'=>$inventoryAvailable ? basename($inventoryPath) : null], 'production'=>'Google Sheets · Inhouse Mining', 'employeeAndFuel'=>['source'=>'Google Sheets · DB','available'=>$dbPeriodAvailable,'year'=>$dbYear]],
            'notes' => array_values(array_filter([
                $inventoryAvailable ? null : 'Inventory transaction snapshot is missing. Production and DB references loaded, but inventory cost and item group values are withheld until data/equipment_inventory.json is provided.',
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

    private function chartGroups(array $groups): array
    {
        $positiveTotal = array_sum(array_filter($groups, fn ($value) => $value > 0));
        $result = [];
        foreach ($groups as $label => $value) $result[] = ['label'=>$label,'value'=>$value,'percentage'=>$value > 0 && $positiveTotal > 0 ? $value / $positiveTotal * 100 : 0,'credit'=>$value < 0];
        usort($result, fn ($a, $b) => abs($b['value']) <=> abs($a['value']));
        return $result;
    }

    private function fuelPrices(array $rows, int $year): array
    {
        $months = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
        foreach ($rows as $row) {
            $normalized = $this->normalizeRow(array_combine(range(0, count($row) - 1), $row) ?: []);
            $label = strtolower((string) ($normalized['3'] ?? ''));
            $unit = strtolower((string) ($normalized['4'] ?? ''));
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
            $activity = trim((string) ($row[22] ?? ''));
            if ($label !== 'employee cost' || $currency !== 'USD' || $entity !== 'BP' || !$activity) continue;
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
