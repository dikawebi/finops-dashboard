<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleSheetsProductionSync
{
    public function sync(): array
    {
        $parsed = $this->readAndParse();
        $rows = $parsed['rows']; $details = $parsed['details']; $monthlyMetrics = $parsed['monthlyMetrics']; $productionPeriods = $parsed['productionPeriods']; $inventoryMovements = $parsed['inventoryMovements'];
        DB::transaction(function () use ($rows, $details, $monthlyMetrics, $productionPeriods, $inventoryMovements): void {
            $dates = array_column($rows, 'production_date');
            DB::table('production_activity_details')->where('source', 'google_sheets')->whereIn('production_date', $dates)->delete();
            DB::table('inventory_movements')->where('source', 'google_sheets')->whereIn('period', array_keys($productionPeriods))->delete();
            DB::table('daily_production')->upsert($rows, ['production_date'], ['period','coal_getting_mt','routes','source','updated_at']);
            DB::table('production_activity_details')->insert($details);
            if ($inventoryMovements) DB::table('inventory_movements')->insert($inventoryMovements);
            $metricLabels = ['coalGetting'=>'Coal Getting','haulingBuaWp'=>'Hauling BUA to Washing Plant','haulingBuaPort'=>'Hauling BUA to Port','haulingWpPort'=>'Hauling Washing Plant to Port','loadingBuaWp'=>'Loading BUA to Washing Plant','loadingBuaPort'=>'Loading BUA to Port','loadingWpPort'=>'Loading Washing Plant to Port','washFeed'=>'Washing Plant Feed','portLoading'=>'Barging / Port Loading'];
            foreach ($monthlyMetrics as $period => $metrics) foreach ($metricLabels as $key => $label) {
                DB::table('monthly_metrics')->updateOrInsert(['metric_key'=>$key,'period'=>$period], ['label'=>$label,'unit'=>'MT','value'=>$metrics[$key] ?? null,'updated_at'=>now(),'created_at'=>now()]);
            }
            DB::table('system_configs')->updateOrInsert(['key'=>'sheetsSync'], ['value'=>json_encode(['last_synced_at'=>now()->toIso8601String(),'rows'=>count($rows),'details'=>count($details)]),'updated_at'=>now(),'created_at'=>now()]);
        });
        return ['rows'=>count($rows),'details'=>count($details),'inventory_movements'=>count($inventoryMovements),'metrics'=>count($monthlyMetrics, COUNT_RECURSIVE) - count($monthlyMetrics),'periods'=>array_keys($productionPeriods),'synced_at'=>now()->toIso8601String()];
    }

    public function preview(): array
    {
        $data = $this->readAndParse();
        return ['rows'=>count($data['rows']),'details'=>count($data['details']),'metric_periods'=>array_keys($data['monthlyMetrics']),'periods'=>array_keys($data['productionPeriods']),'monthly_metrics'=>$data['monthlyMetrics'],'samples'=>array_slice($data['rows'],0,5),'sample_details'=>array_slice($data['details'],0,12)];
    }

    private function readAndParse(): array
    {
        $config = config('services.google_sheets');
        $path = $config['credentials_path'] ?? null;
        if (!($config['enabled'] ?? false) || !$path || !is_file($path)) throw new RuntimeException('Google Sheets belum dikonfigurasi. Periksa GOOGLE_SHEETS_ENABLED dan GOOGLE_SHEETS_CREDENTIALS_PATH.');
        $credentials = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $token = $this->token($credentials);
        $spreadsheet = $config['spreadsheet_id'];
        $sheet = str_replace("'", "''", $config['sheet_name'] ?? 'Produksi');
        $values = [];
        for ($start = 1; $start <= 50000; $start += 500) {
            $range = "'{$sheet}'!A{$start}:AX".($start + 499);
            $url = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet).'/values/'.rawurlencode($range);
            $response = Http::withToken($token)->timeout(60)->get($url, ['valueRenderOption' => 'UNFORMATTED_VALUE']);
            if ($response->failed()) throw new RuntimeException('Google Sheets API gagal: HTTP '.$response->status().' '.$response->json('error.message', ''));
            $chunk = $response->json('values', []);
            if (!$chunk) break;
            array_push($values, ...$chunk);
            if (count($chunk) < 500) break;
        }
        if (count($values) < 4) throw new RuntimeException('Sheet Produksi tidak berisi header dan data yang cukup.');
        $rows = $details = $productionPeriods = $inventoryMovements = [];
        $washMetricColumns = [];
        foreach (['Feed', 'Reject', 'Clean Cooking', 'Barging'] as $metric) $washMetricColumns[$metric] = $this->findHeaderColumn($values[1] ?? [], $metric);
        $sheetMovements = $this->summarizeProductionMovements($values, $washMetricColumns);
        foreach (array_slice($values, 3, null, true) as $offset => $cells) {
            $date = $this->date($cells[0] ?? null);
            if (!$date) continue;
            $period = substr($date, 0, 7);
            $productionPeriods[$period] = true;
            $get = static fn (?int $column): mixed => $column ? ($cells[$column - 1] ?? null) : null;
            $coal = $this->number($get(19));
            $routes = ['bua_to_port' => ['PT BP' => $this->number($get(28)), 'PT SMA' => $this->number($get(30)), 'PT HRS' => $this->number($get(32))], 'wp_to_port' => ['PT BP (WP)' => $this->number($get(29)), 'PT SMA (WP)' => $this->number($get(31)), 'PT HRS (WP)' => $this->number($get(33))]];
            $rows[] = ['production_date' => $date, 'period' => $period, 'coal_getting_mt' => $coal, 'routes' => json_encode($routes), 'source' => 'google_sheets', 'updated_at' => now(), 'created_at' => now()];
            foreach ([['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Freedig','column'=>2,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Blasting','column'=>3,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Parting','column'=>4,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Soil','column'=>5,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Mud','column'=>6,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Freedig','column'=>7,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Blasting','column'=>8,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Parting','column'=>9,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Soil','column'=>10,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Mud','column'=>11,'unit'=>'Bcm'],['activity'=>'Coal Getting','location'=>'Takalot','metric'=>'coal_getting','column'=>17],['activity'=>'Coal Getting','location'=>'North G','metric'=>'coal_getting','column'=>18]] as $mapping) { $mapping['activity_type'] = $mapping['activity'] === 'OB Removal' ? $mapping['metric'] : null; $details[] = $this->detail($date, $mapping, $this->number($get($mapping['column']))); }
            foreach ([['route'=>'BUA to Port','contractors'=>[28=>'PT BP',30=>'PT SMA',32=>'PT HRS']],['route'=>'Washing Plant to Port','contractors'=>[29=>'PT BP (WP)',31=>'PT SMA (WP)',33=>'PT HRS (WP)']],['route'=>'BUA to Washing Plant','contractors'=>[36=>'PT.BP',37=>'PT. SMA',38=>'PT.HRS']]] as $route) foreach ($route['contractors'] as $column=>$contractor) $details[] = $this->detail($date, ['activity'=>'Coal Hauling','route'=>$route['route'],'contractor'=>$contractor,'metric'=>'quantity'], $this->number($get($column)));
            foreach ([34,35] as $column) {
                $route = trim((string) ($values[0][$column - 1] ?? ''));
                $contractor = trim((string) ($values[1][$column - 1] ?? ''));
                if ($route !== '') $details[] = $this->detail($date, ['activity'=>$route,'route'=>$route,'contractor'=>$contractor ?: null,'metric'=>'quantity'], $this->number($get($column)));
            }
            foreach ([36,37,38] as $column) {
                $activity = trim((string) ($values[0][$column - 1] ?? 'Coal Hauling BUA to WP'));
                $contractor = trim((string) ($values[1][$column - 1] ?? ''));
                $details[] = $this->detail($date, ['activity'=>$activity ?: 'Coal Hauling BUA to WP','route'=>'BUA to Washing Plant','contractor'=>$contractor ?: null,'metric'=>'quantity'], $this->number($get($column)));
            }
            foreach (range(40, 46) as $column) {
                $activity = trim((string) ($values[0][$column - 1] ?? ''));
                $equipment = trim((string) ($values[1][$column - 1] ?? ''));
                if ($activity === '' || $equipment === '' || strtolower($activity) === 'coal wash' || in_array(strtolower($equipment), ['feed', 'reject', 'clean cooking', 'barging'], true)) continue;
                $detail = $this->detail($date, ['activity'=>$activity,'route'=>'BUA to Washing Plant','equipment'=>$equipment,'metric'=>'quantity'], $this->number($get($column)));
                $detail['source_key'] = hash('sha256', $detail['source_key'].'|column:'.$column);
                $details[] = $detail;
            }
            foreach ($washMetricColumns as $metric => $column) if ($column) $details[] = $this->detail($date, ['activity'=>'Coal Wash','metric'=>$metric,'unit'=>'MT'], $this->number($get($column)));
            if ($coal !== null) $inventoryMovements[] = ['period'=>$period,'location'=>'bua','movement_type'=>'coalMined','source'=>'google_sheets','qty_mt'=>$coal,'amount_usd'=>0,'metadata'=>json_encode(['source_column'=>'S']),'created_at'=>now(),'updated_at'=>now()];
        }
        if (!$rows) throw new RuntimeException('Tidak ditemukan baris bertanggal yang dapat disinkronkan.');
        $dbRange = "'DB'!A:T";
        $dbUrl = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet).'/values/'.rawurlencode($dbRange);
        $dbResponse = Http::withToken($token)->timeout(60)->get($dbUrl, ['valueRenderOption'=>'UNFORMATTED_VALUE']);
        if ($dbResponse->failed()) throw new RuntimeException('Google Sheets DB tab gagal dibaca: HTTP '.$dbResponse->status().' '.$dbResponse->json('error.message', ''));
        $monthlyMetrics = $this->parseDbMetrics($dbResponse->json('values', []), $productionPeriods);
        $appendMovement = function (string $period, string $location, string $type, float $qty, array $meta = []) use (&$inventoryMovements): void {
            if (abs($qty) < 0.000001) return;
            $inventoryMovements[] = ['period'=>$period,'location'=>$location,'movement_type'=>$type,'source'=>'google_sheets','qty_mt'=>$qty,'amount_usd'=>0,'metadata'=>json_encode($meta),'created_at'=>now(),'updated_at'=>now()];
        };
        foreach ($productionPeriods as $period => $_) {
            $sheet = $sheetMovements[$period] ?? [];
            $db = $monthlyMetrics[$period] ?? [];
            $pick = static function (array $source, string $key, array $fallback, ?float $default = null): ?float {
                if (array_key_exists($key, $source) && $source[$key] !== null) return (float) $source[$key];
                foreach ($fallback as $value) if ($value !== null) return (float) $value;
                return $default;
            };
            $arriveBuaWp = $pick($sheet, 'buaToWpMT', [$db['haulingBuaWp'] ?? null], 0) ?? 0;
            $arriveBuaPort = $pick($sheet, 'buaToPortMT', [$db['haulingBuaPort'] ?? null], 0) ?? 0;
            $arriveWpPort = $pick($sheet, 'wpToPortMT', [$db['haulingWpPort'] ?? null], 0) ?? 0;
            $loadBuaPort = $pick($sheet, 'loadingBuaMT', [$db['loadingBuaPort'] ?? null]);
            $loadWpPort = $pick($sheet, 'loadingWpMT', [$db['loadingWpPort'] ?? null]);
            if ($arriveBuaWp > 0) {
                $appendMovement($period, 'bua', 'transfer_out', -$arriveBuaWp, ['route'=>'BUA to Washing Plant','counterpart'=>'nonWash','phase'=>'hauling']);
                $appendMovement($period, 'nonWash', 'transfer_in', $arriveBuaWp, ['route'=>'BUA to Washing Plant','counterpart'=>'bua','phase'=>'arrival']);
            }
            foreach ([['bua','transitBuaPort','port',$loadBuaPort,$arriveBuaPort,'BUA to Port'],['washed','transitWpPort','port',$loadWpPort,$arriveWpPort,'Washing Plant to Port']] as [$from,$transit,$to,$loaded,$arrived,$route]) {
                if ($loaded !== null && $loaded > 0) {
                    $appendMovement($period, $from, 'transfer_out', -$loaded, ['route'=>$route,'counterpart'=>$transit,'phase'=>'loading']);
                    $appendMovement($period, $transit, 'transfer_in', $loaded, ['route'=>$route,'counterpart'=>$from,'phase'=>'loading']);
                }
                if ($arrived > 0) {
                    $appendMovement($period, $transit, 'transfer_out', -$arrived, ['route'=>$route,'counterpart'=>$to,'phase'=>'hauling']);
                    $appendMovement($period, $to, 'transfer_in', $arrived, ['route'=>$route,'counterpart'=>$transit,'phase'=>'hauling']);
                }
            }
            $feed = $pick($sheet, 'washFeedMT', [$db['washFeed'] ?? null], 0) ?? 0;
            $reject = $pick($sheet, 'washRejectMT', [$db['washReject'] ?? null], 0) ?? 0;
            $clean = $sheet['cleanCookingMT'] ?? null;
            if ($feed > 0) $appendMovement($period, 'nonWash', 'washFeed', -$feed, ['output_clean_mt'=>$clean,'output_reject_mt'=>$reject,'reconciliation_delta_mt'=>$clean === null ? null : round($feed-$clean-$reject,6)]);
            if ($clean !== null && $clean > 0) $appendMovement($period, 'washed', 'washOutput', $clean, ['source'=>'Clean Cooking']);
            if ($reject > 0) $appendMovement($period, 'highAsh', 'washReject', $reject, ['source'=>'Reject']);
            $barging = $sheet['bargingMT'] ?? null;
            if ($barging !== null && $barging > 0) $appendMovement($period, 'port', 'barging', -$barging, ['source'=>'Google Sheets Coal Wash / Barging']);
        }
        return compact('rows','details','monthlyMetrics','productionPeriods','inventoryMovements');
    }

    /** Mirrors the reference parser: month-end recap values replace daily route subtotals. */
    private function summarizeProductionMovements(array $values, array $washMetricColumns): array
    {
        $headers = array_map(static fn ($value): string => strtolower(trim((string) $value)), $values[0] ?? []);
        $columns = [];
        foreach (['buaToPortMT'=>'Coal Hauling BUA to Port','wpToPortMT'=>'Coal Hauling WP to Port','buaToWpMT'=>'Coal Hauling BUA to WP','loadingBuaMT'=>'Coal Loading BUA','loadingWpMT'=>'Coal Loading WP to Port'] as $key=>$header) {
            $columns[$key] = array_keys($headers, strtolower($header), true);
        }
        $monthly = $observed = [];
        foreach (array_slice($values, 3, null, true) as $cells) {
            $date = $this->date($cells[0] ?? null);
            if (!$date) continue;
            $period = substr($date, 0, 7);
            $totals = [];
            foreach ($columns as $key=>$indexes) $totals[$key] = array_sum(array_map(fn ($index): float => $this->number($cells[$index] ?? null) ?? 0, $indexes));
            $before = $observed[$period] ?? [];
            $matches = 0;
            foreach (['buaToPortMT','wpToPortMT','buaToWpMT'] as $key) if (($totals[$key] ?? 0) > 0 && ($before[$key] ?? 0) > 0 && $totals[$key] >= $before[$key] * 0.7 && $totals[$key] <= $before[$key] * 1.5) $matches++;
            $lastDay = (int) substr($date, 8, 2) === (int) gmdate('t', strtotime($date.' 00:00:00 UTC'));
            $rawCoal = trim((string) ($cells[18] ?? ''));
            $isRecap = array_sum($totals) > 0 && $lastDay && (($rawCoal === '' || $rawCoal === '-') || $matches >= 2);
            if ($isRecap) {
                foreach ($totals as $key=>$value) $monthly[$period][$key] = $value;
                foreach ($washMetricColumns as $metric=>$column) if ($column) $monthly[$period][match ($metric) {'Feed'=>'washFeedMT','Reject'=>'washRejectMT','Clean Cooking'=>'cleanCookingMT','Barging'=>'bargingMT'}] = $this->number($cells[$column-1] ?? null) ?? 0;
                continue;
            }
            foreach ($totals as $key=>$value) { $monthly[$period][$key] = ($monthly[$period][$key] ?? 0) + $value; $observed[$period][$key] = ($observed[$period][$key] ?? 0) + $value; }
            // Wash and barging cells are month-end recap totals, not daily values.
        }
        return $monthly;
    }

    private function parseDbMetrics(array $rows, array $productionPeriods): array
    {
        $definitions = [
            'coalGetting'=>['Coal Getting','MT'],
            'haulingBuaWp'=>['Total Hauling BUA To Wash Plant','MT'],
            'haulingBuaPort'=>['Total Hauling Bua Stock to Port','MT'],
            'haulingWpPort'=>['Total hauling Wash Plant to Port','MT'],
            'loadingBuaWp'=>['Total Loading BUA To Wash Plant','MT'],
            'loadingBuaPort'=>['Total Loading Bua Stock to Port','MT'],
            'loadingWpPort'=>['Total Loading Wash Plant to Port','MT'],
            'washFeed'=>['Feed','MT'],
            'portLoading'=>['Coal Loading Port (BLC)','MT'],
        ];
        $metrics = [];
        foreach ($definitions as $key => [$label, $unit]) {
            $matches = array_values(array_filter($rows, static fn (array $row): bool =>
                strtolower(trim((string) ($row[2] ?? ''))) === strtolower($label)
                && strtolower(trim((string) ($row[3] ?? ''))) === strtolower($unit)
            ));
            if (count($matches) !== 1) throw new RuntimeException("Google Sheets DB metric row is missing or ambiguous: {$label} ({$unit}).");
            $metrics[$key] = $matches[0];
        }

        $year = $productionPeriods ? (int) substr(max(array_keys($productionPeriods)), 0, 4) : (int) date('Y');
        $result = [];
        for ($month = 1; $month <= 12; $month++) {
            $period = sprintf('%04d-%02d', $year, $month);
            foreach ($definitions as $key => $_definition) $result[$period][$key] = $this->number($metrics[$key][7 + $month] ?? null);
        }
        return $result;
    }

    private function token(array $credentials): string
    {
        $now = time(); $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $unsigned = $encode(['alg'=>'RS256','typ'=>'JWT']).'.'.$encode(['iss'=>$credentials['client_email'],'scope'=>'https://www.googleapis.com/auth/spreadsheets.readonly','aud'=>$credentials['token_uri'],'iat'=>$now,'exp'=>$now+3600]);
        $key = openssl_pkey_get_private($credentials['private_key']);
        if (!$key || !openssl_sign($unsigned,$signature,$key,OPENSSL_ALGO_SHA256)) throw new RuntimeException('Tidak dapat menandatangani token Google Service Account.');
        $response = Http::asForm()->timeout(30)->post($credentials['token_uri'], ['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$unsigned.'.'.rtrim(strtr(base64_encode($signature),'+/','-_'),'=')]);
        if ($response->failed()) throw new RuntimeException('Autentikasi Google gagal: HTTP '.$response->status());
        return (string) $response->json('access_token');
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-') return null;
        if (is_numeric($value)) return (float) $value;
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $value));
        return $clean !== '' && is_numeric($clean) ? (float) $clean : null;
    }

    private function findHeaderColumn(array $headers, string $wanted): ?int
    {
        $normalize = static fn (mixed $value): string => strtolower(preg_replace('/\s+/', ' ', trim((string) $value)));
        $needle = $normalize($wanted);
        foreach ($headers as $index => $header) if ($normalize($header) === $needle) return $index + 1;
        return null;
    }

    private function date(mixed $value): ?string
    {
        if (is_numeric($value)) return gmdate('Y-m-d', (int) (($value - 25569) * 86400));
        $time = strtotime((string) $value);
        return $time ? date('Y-m-d', $time) : null;
    }

    private function detail(string $date, array $mapping, ?float $value): array
    {
        $mapping += ['activity'=>'','location'=>null,'activity_type'=>null,'route'=>null,'contractor'=>null,'equipment'=>null,'metric'=>'quantity','unit'=>str_starts_with($mapping['activity'], 'OB Removal') ? 'Bcm' : 'MT'];
        $key = implode('|', [$date,$mapping['activity'],$mapping['location'],$mapping['activity_type'],$mapping['route'],$mapping['contractor'],$mapping['equipment'],$mapping['metric']]);
        return ['production_date'=>$date,'activity'=>$mapping['activity'],'location'=>$mapping['location'],'activity_type'=>$mapping['activity_type'],'route'=>$mapping['route'],'contractor'=>$mapping['contractor'],'equipment'=>$mapping['equipment'],'metric'=>$mapping['metric'],'value'=>$value,'unit'=>$mapping['unit'],'source_key'=>hash('sha256',$key),'source'=>'google_sheets','created_at'=>now(),'updated_at'=>now()];
    }
}
