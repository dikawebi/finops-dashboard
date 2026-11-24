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
        $rows = $parsed['rows']; $details = $parsed['details']; $monthlyMetrics = $parsed['monthlyMetrics']; $productionPeriods = $parsed['productionPeriods']; $inventoryMovements = $parsed['inventoryMovements']; $dbCostSummary = $parsed['dbCostSummary']; $dbAccrualItems = $parsed['dbAccrualItems']; $dbDailyCogpParameters = $parsed['dbDailyCogpParameters']; $dbMiningBreakdown = $parsed['dbMiningBreakdown']; $dbEntityVolumes = $parsed['dbEntityVolumes']; $overheadAllocations = $parsed['overheadAllocations']; $washCostCategories = $parsed['washCostCategories']; $internalWaPeriods = $parsed['internalWaPeriods'];
        DB::transaction(function () use ($rows, $details, $monthlyMetrics, $productionPeriods, $inventoryMovements, $dbCostSummary, $dbAccrualItems, $dbDailyCogpParameters, $dbMiningBreakdown, $dbEntityVolumes, $overheadAllocations, $washCostCategories, $internalWaPeriods): void {
            $dates = array_column($rows, 'production_date');
            DB::table('production_activity_details')->where('source', 'google_sheets')->whereIn('production_date', $dates)->delete();
            DB::table('inventory_movements')->where('source', 'google_sheets')->whereIn('period', array_keys($productionPeriods))->delete();
            DB::table('daily_production')->upsert($rows, ['production_date'], ['period','coal_getting_mt','routes','source','updated_at']);
            DB::table('production_activity_details')->insert($details);
            if ($inventoryMovements) DB::table('inventory_movements')->insert($inventoryMovements);
            $metricLabels = ['coalGetting'=>'Coal Getting','haulingBuaWp'=>'Hauling BUA to Washing Plant','haulingBuaPort'=>'Hauling BUA to Port','haulingWpPort'=>'Hauling Washing Plant to Port','loadingBuaWp'=>'Loading BUA to Washing Plant','loadingBuaPort'=>'Loading BUA to Port','loadingWpPort'=>'Loading Washing Plant to Port','washFeed'=>'Washing Plant Feed','washReject'=>'Washing Plant Reject','washCoal'=>'Washed Coal','portLoading'=>'Barging / Port Loading','portToIsp'=>'Port to ISP','ispToTaboneo'=>'ISP to Taboneo','portToTaboneo'=>'Port to Taboneo','wasteRemoval'=>'Waste Removal','topsoil'=>'Topsoil','mud'=>'Mud','blasting'=>'Blasting','freedig'=>'Freedig','miningOperationCost'=>'Mining Operation Cost'];
            $metricUnits = ['wasteRemoval'=>'Bcm','topsoil'=>'Bcm','mud'=>'Bcm','blasting'=>'Bcm','freedig'=>'Bcm','miningOperationCost'=>'USD'];
            foreach ($monthlyMetrics as $period => $metrics) foreach ($metricLabels as $key => $label) {
                DB::table('monthly_metrics')->updateOrInsert(['metric_key'=>$key,'period'=>$period], ['label'=>$label,'unit'=>$metricUnits[$key] ?? 'MT','value'=>$metrics[$key] ?? null,'updated_at'=>now(),'created_at'=>now()]);
            }
            $summaryPeriods = array_keys($monthlyMetrics);
            DB::table('db_cost_summary')->whereIn('period', $summaryPeriods)->delete();
            $summaryRows = [];
            foreach ($dbCostSummary as $period => $items) foreach ($items as $item) {
                $summaryRows[] = ['period'=>$period,'entity'=>$item['entity'],'activity'=>$item['activity'],'amount'=>$item['amount'],'currency'=>'USD','created_at'=>now(),'updated_at'=>now()];
            }
            foreach (array_chunk($summaryRows, 500) as $chunk) DB::table('db_cost_summary')->insert($chunk);
            $accrualPeriods = array_values(array_unique(array_column($dbAccrualItems, 'period')));
            DB::table('db_accrual_items')->whereIn('period', $summaryPeriods)->delete();
            foreach (array_chunk($dbAccrualItems, 500) as $chunk) DB::table('db_accrual_items')->insert($chunk);
            DB::table('db_daily_cogp_parameters')->whereIn('period', $summaryPeriods)->delete();
            foreach (array_chunk($dbDailyCogpParameters, 500) as $chunk) DB::table('db_daily_cogp_parameters')->insert($chunk);
            DB::table('db_mining_breakdown')->whereIn('period', $summaryPeriods)->delete();
            foreach (array_chunk($dbMiningBreakdown, 500) as $chunk) DB::table('db_mining_breakdown')->insert($chunk);
            DB::table('db_entity_volumes')->whereIn('period', $summaryPeriods)->delete();
            foreach (array_chunk($dbEntityVolumes, 500) as $chunk) DB::table('db_entity_volumes')->insert($chunk);
            foreach ($overheadAllocations as &$allocation) {
                $allocation['source_synced_at'] = now();
                $allocation['updated_at'] = now();
                $allocation['created_at'] = now();
            }
            unset($allocation);
            DB::table('google_sheets_inventory_cost_allocations')->upsert(
                $overheadAllocations,
                ['period'],
                ['overhead_pool_usd','isp_rent_usd','allocated_bua_usd','allocated_wash_usd','allocated_port_usd','allocated_isp_usd','source_sheet','source_synced_at','updated_at']
            );
            $this->persistInternalWaPeriods($internalWaPeriods);
            $washPeriods = array_values(array_unique(array_column($washCostCategories, 'period')));
            if ($washPeriods) {
                foreach ($washCostCategories as &$category) $category['source_synced_at'] = now();
                unset($category);
                DB::table('google_sheets_wash_cost_categories')->whereIn('period', $washPeriods)->delete();
                foreach (array_chunk($washCostCategories, 500) as $chunk) DB::table('google_sheets_wash_cost_categories')->insert($chunk);
            }
            DB::table('system_configs')->updateOrInsert(['key'=>'sheetsSync'], ['value'=>json_encode(['last_synced_at'=>now()->toIso8601String(),'rows'=>count($rows),'details'=>count($details),'db_cost_periods'=>$summaryPeriods,'db_accrual_periods'=>$accrualPeriods,'db_accrual_rows'=>count($dbAccrualItems),'db_mining_breakdown_rows'=>count($dbMiningBreakdown),'db_entity_volume_rows'=>count($dbEntityVolumes),'db_wash_cost_category_rows'=>count($washCostCategories)]),'updated_at'=>now(),'created_at'=>now()]);
        });
        return ['rows'=>count($rows),'details'=>count($details),'inventory_movements'=>count($inventoryMovements),'metrics'=>count($monthlyMetrics, COUNT_RECURSIVE) - count($monthlyMetrics),'db_cost_rows'=>array_sum(array_map('count', $dbCostSummary)),'db_accrual_rows'=>count($dbAccrualItems),'daily_cogp_parameters'=>count($dbDailyCogpParameters),'db_mining_breakdown_rows'=>count($dbMiningBreakdown),'db_entity_volume_rows'=>count($dbEntityVolumes),'overhead_allocation_periods'=>count($overheadAllocations),'internal_wa_periods'=>count($internalWaPeriods),'internal_wa_source'=>'Google Sheets · WA COGP-COGS','wash_cost_category_rows'=>count($washCostCategories),'wash_cost_periods'=>array_values(array_unique(array_column($washCostCategories,'period'))),'periods'=>array_keys($productionPeriods),'synced_at'=>now()->toIso8601String()];
    }

    /** Synchronize only the WA COGP-COGS inputs used by Internal Cost/MT. */
    public function syncInternalWaCostPerMt(): array
    {
        $periods = $this->readAndParse()['internalWaPeriods'];
        $syncedAt = now();
        $this->persistInternalWaPeriods($periods, $syncedAt);
        return ['periods'=>array_column($periods, 'period'),'rows'=>count($periods),'source'=>'Google Sheets · WA COGP-COGS','synced_at'=>$syncedAt->toIso8601String()];
    }

    private function persistInternalWaPeriods(array $periods, $syncedAt = null): void
    {
        $syncedAt ??= now();
        $rows = array_map(static fn (array $item): array => [
            'period'=>$item['period'],'payload'=>json_encode($item['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_sheet'=>'WA COGP-COGS','source_synced_at'=>$syncedAt,'created_at'=>$syncedAt,'updated_at'=>$syncedAt,
        ], $periods);
        $years = array_values(array_unique(array_map(static fn (array $row): string => substr($row['period'], 0, 4), $rows)));
        $yearPeriods = [];
        foreach ($years as $year) for ($month=1; $month<=12; $month++) $yearPeriods[] = sprintf('%04d-%02d', (int) $year, $month);
        DB::transaction(function () use ($rows, $yearPeriods): void {
            DB::table('google_sheets_internal_wa_periods')->whereIn('period', $yearPeriods)->delete();
            DB::table('google_sheets_internal_wa_periods')->insert($rows);
        });
    }

    /** Refresh only the Google Sheets allocation snapshot without rewriting production or DB summary data. */
    public function syncInventoryOverheadAllocations(): array
    {
        $allocations = $this->readAndParse()['overheadAllocations'];
        $syncedAt = now();
        foreach ($allocations as &$allocation) {
            $allocation['source_synced_at'] = $syncedAt;
            $allocation['updated_at'] = $syncedAt;
            $allocation['created_at'] = $syncedAt;
        }
        unset($allocation);
        DB::transaction(fn () => DB::table('google_sheets_inventory_cost_allocations')->upsert(
            $allocations,
            ['period'],
            ['overhead_pool_usd','isp_rent_usd','allocated_bua_usd','allocated_wash_usd','allocated_port_usd','allocated_isp_usd','source_sheet','source_synced_at','updated_at']
        ));
        return ['periods'=>array_column($allocations, 'period'),'rows'=>count($allocations),'source'=>'Google Sheets · WA COGP-COGS','synced_at'=>$syncedAt->toIso8601String()];
    }

    /** Refresh only WA-style wash cost categories sourced from the DB sheet block. */
    public function syncWashCostCategories(): array
    {
        $categories = $this->readAndParse()['washCostCategories'];
        $periods = array_values(array_unique(array_column($categories, 'period')));
        if (!$periods) throw new RuntimeException('Google Sheets DB tab has no BP Wash Plant Cost categories to sync.');
        $syncedAt = now();
        foreach ($categories as &$category) {
            $category['source_synced_at'] = $syncedAt;
            $category['updated_at'] = $syncedAt;
            $category['created_at'] = $syncedAt;
        }
        unset($category);
        DB::transaction(function () use ($periods, $categories): void {
            DB::table('google_sheets_wash_cost_categories')->whereIn('period', $periods)->delete();
            foreach (array_chunk($categories, 500) as $chunk) DB::table('google_sheets_wash_cost_categories')->insert($chunk);
        });
        return ['periods'=>$periods,'rows'=>count($categories),'source'=>'Google Sheets · DB / BP Wash Plant Cost','synced_at'=>$syncedAt->toIso8601String()];
    }

    public function preview(): array
    {
        $data = $this->readAndParse();
        return ['rows'=>count($data['rows']),'details'=>count($data['details']),'metric_periods'=>array_keys($data['monthlyMetrics']),'periods'=>array_keys($data['productionPeriods']),'monthly_metrics'=>$data['monthlyMetrics'],'db_cost_rows'=>array_sum(array_map('count', $data['dbCostSummary'])),'overhead_allocation_periods'=>count($data['overheadAllocations']),'sample_overhead_allocation'=>array_values(array_filter($data['overheadAllocations'], static fn (array $row): bool => $row['period'] === '2026-10')),'internal_wa_periods'=>count($data['internalWaPeriods']),'sample_internal_wa'=>array_values(array_filter($data['internalWaPeriods'], static fn (array $row): bool => $row['period'] === '2026-10')),'wash_cost_category_rows'=>count($data['washCostCategories']),'sample_wash_cost_categories'=>array_values(array_filter($data['washCostCategories'], static fn (array $row): bool => in_array($row['period'], ['2026-09','2026-10'], true))),'db_accrual_rows'=>count($data['dbAccrualItems']),'daily_cogp_parameters'=>count($data['dbDailyCogpParameters']),'db_mining_breakdown_rows'=>count($data['dbMiningBreakdown']),'db_entity_volume_rows'=>count($data['dbEntityVolumes']),'sample_mining_breakdown'=>array_values(array_filter($data['dbMiningBreakdown'], static fn (array $row): bool => $row['period'] === '2026-10')),'sample_entity_volumes'=>array_values(array_filter($data['dbEntityVolumes'], static fn (array $row): bool => $row['period'] === '2026-10')),'sample_accruals'=>array_slice($data['dbAccrualItems'],0,8),'samples'=>array_slice($data['rows'],0,5),'sample_details'=>array_slice($data['details'],0,12)];
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
        $dateColumn = array_search('PERIODE', array_map(static fn ($value): string => strtoupper(trim((string) $value)), $values[0] ?? []), true);
        if ($dateColumn === false) throw new RuntimeException('Kolom tanggal tidak ditemukan: header PERIODE tidak ada pada baris pertama sheet Produksi.');
        $coalColumn = $this->findSectionSubheaderColumn($values[0] ?? [], $values[1] ?? [], 'Coal Getting', 'Actual', $dateColumn);
        if ($coalColumn === null) throw new RuntimeException('Kolom Coal Getting · Actual tidak ditemukan pada sheet Produksi.');
        // The source sheet has an extra leading day-number column; anchor the
        // existing fixed column map to the PERIODE header instead of assuming A.
        $columnOffset = $dateColumn;
        $rows = $details = $productionPeriods = $inventoryMovements = [];
        $washMetricColumns = [];
        foreach (['Feed', 'Reject', 'Clean Cooking', 'Barging'] as $metric) $washMetricColumns[$metric] = $this->findHeaderColumn($values[1] ?? [], $metric);
        $sheetMovements = $this->summarizeProductionMovements($values, $washMetricColumns);
        foreach (array_slice($values, 3, null, true) as $offset => $cells) {
            $date = $this->date($cells[$dateColumn] ?? null);
            if (!$date) continue;
            $period = substr($date, 0, 7);
            $productionPeriods[$period] = true;
            $get = static fn (?int $column): mixed => $column ? ($cells[$column - 1 + $columnOffset] ?? null) : null;
            $coal = $this->number($get($coalColumn));
            $routes = ['bua_to_port' => ['PT BP' => $this->number($get(28)), 'PT SMA' => $this->number($get(30)), 'PT HRS' => $this->number($get(32))], 'wp_to_port' => ['PT BP (WP)' => $this->number($get(29)), 'PT SMA (WP)' => $this->number($get(31)), 'PT HRS (WP)' => $this->number($get(33))]];
            $rows[] = ['production_date' => $date, 'period' => $period, 'coal_getting_mt' => $coal, 'routes' => json_encode($routes), 'source' => 'google_sheets', 'updated_at' => now(), 'created_at' => now()];
            foreach ([['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Freedig','column'=>2,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Blasting','column'=>3,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Parting','column'=>4,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Soil','column'=>5,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'Takalot','metric'=>'Mud','column'=>6,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Freedig','column'=>7,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Blasting','column'=>8,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Parting','column'=>9,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Soil','column'=>10,'unit'=>'Bcm'],['activity'=>'OB Removal','location'=>'North G','metric'=>'Mud','column'=>11,'unit'=>'Bcm'],['activity'=>'Coal Getting','location'=>'Takalot','metric'=>'coal_getting','column'=>17],['activity'=>'Coal Getting','location'=>'North G','metric'=>'coal_getting','column'=>18]] as $mapping) { $mapping['activity_type'] = $mapping['activity'] === 'OB Removal' ? $mapping['metric'] : null; $details[] = $this->detail($date, $mapping, $this->number($get($mapping['column']))); }
            foreach ([['route'=>'BUA to Port','contractors'=>[28=>'PT BP',30=>'PT SMA',32=>'PT HRS']],['route'=>'Washing Plant to Port','contractors'=>[29=>'PT BP (WP)',31=>'PT SMA (WP)',33=>'PT HRS (WP)']],['route'=>'BUA to Washing Plant','contractors'=>[36=>'PT.BP',37=>'PT. SMA',38=>'PT.HRS']]] as $route) foreach ($route['contractors'] as $column=>$contractor) $details[] = $this->detail($date, ['activity'=>'Coal Hauling','route'=>$route['route'],'contractor'=>$contractor,'metric'=>'quantity'], $this->number($get($column)));
            foreach ([34,35] as $column) {
                $route = trim((string) ($values[0][$column - 1 + $columnOffset] ?? ''));
                $contractor = trim((string) ($values[1][$column - 1 + $columnOffset] ?? ''));
                if ($route !== '') $details[] = $this->detail($date, ['activity'=>$route,'route'=>$route,'contractor'=>$contractor ?: null,'metric'=>'quantity'], $this->number($get($column)));
            }
            foreach ([36,37,38] as $column) {
                $activity = trim((string) ($values[0][$column - 1 + $columnOffset] ?? 'Coal Hauling BUA to WP'));
                $contractor = trim((string) ($values[1][$column - 1 + $columnOffset] ?? ''));
                $details[] = $this->detail($date, ['activity'=>$activity ?: 'Coal Hauling BUA to WP','route'=>'BUA to Washing Plant','contractor'=>$contractor ?: null,'metric'=>'quantity'], $this->number($get($column)));
            }
            foreach (range(40, 46) as $column) {
                $activity = trim((string) ($values[0][$column - 1 + $columnOffset] ?? ''));
                $equipment = trim((string) ($values[1][$column - 1 + $columnOffset] ?? ''));
                if ($activity === '' || $equipment === '' || strtolower($activity) === 'coal wash' || in_array(strtolower($equipment), ['feed', 'reject', 'clean cooking', 'barging'], true)) continue;
                $detail = $this->detail($date, ['activity'=>$activity,'route'=>'BUA to Washing Plant','equipment'=>$equipment,'metric'=>'quantity'], $this->number($get($column)));
                $detail['source_key'] = hash('sha256', $detail['source_key'].'|column:'.$column);
                $details[] = $detail;
            }
            foreach ($washMetricColumns as $metric => $column) if ($column) $details[] = $this->detail($date, ['activity'=>'Coal Wash','metric'=>$metric,'unit'=>'MT'], $this->number($get($column - $columnOffset)));
            if ($coal !== null) $inventoryMovements[] = ['period'=>$period,'location'=>'bua','movement_type'=>'coalMined','source'=>'google_sheets','qty_mt'=>$coal,'amount_usd'=>0,'metadata'=>json_encode(['source_column'=>$this->columnLabel(19 + $columnOffset)]),'created_at'=>now(),'updated_at'=>now()];
        }
        if (!$rows) throw new RuntimeException('Tidak ditemukan baris bertanggal yang dapat disinkronkan.');
        $dbRange = "'DB'!A:T";
        $dbUrl = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet).'/values/'.rawurlencode($dbRange);
        $dbResponse = Http::withToken($token)->timeout(60)->get($dbUrl, ['valueRenderOption'=>'UNFORMATTED_VALUE']);
        if ($dbResponse->failed()) throw new RuntimeException('Google Sheets DB tab gagal dibaca: HTTP '.$dbResponse->status().' '.$dbResponse->json('error.message', ''));
        $dbRows = $dbResponse->json('values', []);
        $waRange = "'WA COGP-COGS'!A:N";
        $waUrl = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet).'/values/'.rawurlencode($waRange);
        $waResponse = Http::withToken($token)->timeout(60)->get($waUrl, ['valueRenderOption'=>'UNFORMATTED_VALUE']);
        if ($waResponse->failed()) throw new RuntimeException('Google Sheets WA COGP-COGS tab gagal dibaca: HTTP '.$waResponse->status().' '.$waResponse->json('error.message', ''));
        $waRows = $waResponse->json('values', []);
        $monthlyMetrics = $this->parseDbMetrics($dbRows, $productionPeriods);
        $dbCostSummary = $this->parseDbCostSummary($dbRows, array_keys($monthlyMetrics));
        $overheadAllocations = $this->parseWaOverheadAllocations($waRows, array_keys($monthlyMetrics));
        $internalWaPeriods = $this->parseInternalWaPeriods($waRows, array_keys($monthlyMetrics));
        $washCostCategories = $this->parseDbWashCostCategories($dbRows, array_keys($monthlyMetrics), $dbCostSummary);
        $dbAccrualItems = $this->parseDbAccrualItems($dbRows, array_keys($monthlyMetrics), $monthlyMetrics);
        $dbDailyCogpParameters = $this->parseDbDailyCogpParameters($dbRows, array_keys($monthlyMetrics));
        $dbMiningBreakdown = $this->parseDbMiningBreakdown($dbRows, array_keys($monthlyMetrics));
        $dbEntityVolumes = $this->parseDbEntityVolumes($dbRows, array_keys($monthlyMetrics));
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
            if ($reject > 0) $appendMovement($period, 'washed', 'washReject', $reject, ['source'=>'Reject','effect'=>'reduce_wash_only']);
            $barging = $sheet['bargingMT'] ?? null;
            if ($barging !== null && $barging > 0) $appendMovement($period, 'port', 'barging', -$barging, ['source'=>'Google Sheets Coal Wash / Barging']);
        }
        return compact('rows','details','monthlyMetrics','dbCostSummary','dbAccrualItems','dbDailyCogpParameters','dbMiningBreakdown','dbEntityVolumes','productionPeriods','inventoryMovements','overheadAllocations','internalWaPeriods','washCostCategories');
    }

    private function parseDbMiningBreakdown(array $rows, array $periods): array
    {
        $labels = ['OB Removal include blasting','OB removal freedig','OB Overdistance','Topsoil','Mud','Coal Getting + Hauling 1km','Coal Hauling Overdistance'];
        $section = '';
        $result = [];
        foreach ($rows as $row) {
            $heading = strtolower(trim((string) ($row[1] ?? '')));
            if ($heading === 'mining operation cost') $section = 'operation';
            elseif ($heading === 'fuel mining operation cost') $section = 'fuel';
            elseif ($heading !== '') $section = '';
            $label = trim((string) ($row[2] ?? ''));
            if ($section === '' || strtoupper(trim((string) ($row[4] ?? ''))) !== 'HRS' || !in_array(strtolower($label), array_map('strtolower', $labels), true)) continue;
            foreach ($periods as $period) {
                $month = (int) substr($period, 5, 2);
                $amount = $this->number($row[7 + $month] ?? null);
                if ($amount === null) continue;
                $key = implode('|', [$period, strtolower($label), $section, 'HRS']);
                $result[$key] = ['period'=>$period,'label'=>$label,'section'=>$section,'entity'=>'HRS','amount_usd'=>$amount,'created_at'=>now(),'updated_at'=>now()];
            }
        }
        return array_values($result);
    }

    private function parseDbEntityVolumes(array $rows, array $periods): array
    {
        $activities = [
            'coal hauling bua to wash plant'=>['5000407','Coal Hauling ROM BUA to WP'],
            'coal hauling bua stock to port'=>['5000409','Coal Hauling ROM BUA to Port'],
            'coal hauling wash plant to port'=>['5000408','Coal Hauling WP to Port'],
        ];
        $result = [];
        foreach ($rows as $row) {
            $match = $activities[strtolower(trim((string) ($row[2] ?? '')))] ?? null;
            $entity = strtoupper(trim((string) ($row[4] ?? '')));
            if (!$match || !in_array($entity, ['HRS','BP','SMA'], true) || strtolower(trim((string) ($row[3] ?? ''))) !== 'mt') continue;
            foreach ($periods as $period) {
                $month = (int) substr($period, 5, 2);
                $qty = $this->number($row[7 + $month] ?? null);
                if ($qty === null) continue;
                $key = implode('|', [$period, $match[0], $entity]);
                $result[$key] = ['period'=>$period,'account_code'=>$match[0],'activity'=>$match[1],'entity'=>$entity,'qty'=>$qty,'unit'=>'MT','created_at'=>now(),'updated_at'=>now()];
            }
        }
        return array_values($result);
    }

    /** Mirrors the reference parser: month-end recap values replace daily route subtotals. */
    private function summarizeProductionMovements(array $values, array $washMetricColumns): array
    {
        $headers = array_map(static fn ($value): string => strtolower(trim((string) $value)), $values[0] ?? []);
        $dateColumn = array_search('periode', $headers, true);
        if ($dateColumn === false) throw new RuntimeException('Kolom tanggal tidak ditemukan: header PERIODE tidak ada pada baris pertama sheet Produksi.');
        $columns = [];
        foreach (['buaToPortMT'=>'Coal Hauling BUA to Port','wpToPortMT'=>'Coal Hauling WP to Port','buaToWpMT'=>'Coal Hauling BUA to WP','loadingBuaMT'=>'Coal Loading BUA','loadingWpMT'=>'Coal Loading WP to Port'] as $key=>$header) {
            $columns[$key] = array_keys($headers, strtolower($header), true);
        }
        $monthly = $observed = [];
        foreach (array_slice($values, 3, null, true) as $cells) {
            $date = $this->date($cells[$dateColumn] ?? null);
            if (!$date) continue;
            $period = substr($date, 0, 7);
            $totals = [];
            foreach ($columns as $key=>$indexes) $totals[$key] = array_sum(array_map(fn ($index): float => $this->number($cells[$index] ?? null) ?? 0, $indexes));
            $before = $observed[$period] ?? [];
            $matches = 0;
            foreach (['buaToPortMT','wpToPortMT','buaToWpMT'] as $key) if (($totals[$key] ?? 0) > 0 && ($before[$key] ?? 0) > 0 && $totals[$key] >= $before[$key] * 0.7 && $totals[$key] <= $before[$key] * 1.5) $matches++;
            $lastDay = (int) substr($date, 8, 2) === (int) gmdate('t', strtotime($date.' 00:00:00 UTC'));
            $rawCoal = trim((string) ($cells[$dateColumn + 18] ?? ''));
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
            'washReject'=>['Reject','MT'],
            'washCoal'=>['Wash Coal','MT'],
            'portLoading'=>['Coal Loading Port (BLC)','MT'],
            'portToIsp'=>['Transhipment Port to ISP','MT'],
            'ispToTaboneo'=>['Transhipment ISP to Taboneo','MT'],
            'portToTaboneo'=>['Transhipment Port to Taboneo','MT'],
            'wasteRemoval'=>['Total Waste removal','Bcm'],
            'topsoil'=>['Topsoil','Bcm'],
            'mud'=>['Mud','Bcm'],
            'blasting'=>['Blasting','Bcm'],
            'freedig'=>['Freedig','Bcm'],
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

        $miningCostRows = array_values(array_filter($rows, static fn (array $row): bool =>
            in_array(strtolower(trim((string) ($row[2] ?? ''))), ['total mining operation cost', 'mining operation cost'], true)
        ));

        $year = $productionPeriods ? (int) substr(max(array_keys($productionPeriods)), 0, 4) : (int) date('Y');
        $result = [];
        for ($month = 1; $month <= 12; $month++) {
            $period = sprintf('%04d-%02d', $year, $month);
            foreach ($definitions as $key => $_definition) {
                $value = $this->number($metrics[$key][7 + $month] ?? null);
                $result[$period][$key] = $key === 'washReject' && $value !== null ? abs($value) : $value;
            }
            $result[$period]['miningOperationCost'] = $this->number($miningCostRows[0][7 + $month] ?? null);
        }
        return $result;
    }

    /** Store the live DB-sheet inputs required by the reference daily COGP formula. */
    private function parseDbDailyCogpParameters(array $rows, array $periods): array
    {
        $monthlyLabels = [
            'fuel price including transport',
            'base coal getting distance',
            'actual coal getting distance',
            'base hauling distance ob',
            'ob hauling distance actual',
        ];
        $parameters = [];
        $section = '';
        foreach ($rows as $row) {
            $heading = strtolower(trim((string) ($row[1] ?? '')));
            if ($heading === 'mining operation cost') $section = 'operation';
            elseif ($heading === 'fuel mining operation cost') $section = 'fuel';
            elseif ($heading !== '') $section = '';

            $label = strtolower(trim((string) ($row[2] ?? '')));
            if ($section !== '' && $label !== '' && strtoupper(trim((string) ($row[4] ?? ''))) === 'HRS') {
                $value = $this->number($row[6] ?? null);
                if ($value !== null) foreach ($periods as $period) {
                    $key = implode('|', [$period, $section, $label, 'HRS']);
                    $parameters[$key] = [
                        'period'=>$period,'section'=>$section,'label'=>$label,'entity'=>'HRS',
                        'value'=>$value,'unit'=>trim((string) ($row[3] ?? '')) ?: null,
                        'created_at'=>now(),'updated_at'=>now(),
                    ];
                }
            }

            if (!in_array($label, $monthlyLabels, true)) continue;
            if ($label === 'fuel price including transport' && strtolower(trim((string) ($row[3] ?? ''))) !== 'us$/litre') continue;
            foreach ($periods as $period) {
                $month = (int) substr($period, 5, 2);
                $value = $this->number($row[7 + $month] ?? null);
                if ($value === null) continue;
                $key = implode('|', [$period, 'monthly', $label, '']);
                $parameters[$key] = [
                    'period'=>$period,'section'=>'monthly','label'=>$label,'entity'=>'',
                    'value'=>$value,'unit'=>trim((string) ($row[3] ?? '')) ?: null,
                    'created_at'=>now(),'updated_at'=>now(),
                ];
            }
        }

        return array_values($parameters);
    }

    private function parseWaOverheadAllocations(array $rows, array $periods): array
    {
        $definitions = [
            'OVH JKT & PC + ISP Rent pool' => 'overhead_pool_usd',
            'ISP Rent total — allocation pool' => 'isp_rent_usd',
            'OVH & ISP Rent allocated — BUA' => 'allocated_bua_usd',
            'OVH & ISP Rent allocated — WP' => 'allocated_wash_usd',
            'OVH & ISP Rent allocated — Port' => 'allocated_port_usd',
            'OVH & ISP Rent allocated — ISP' => 'allocated_isp_usd',
        ];
        $normalize = static fn (mixed $value): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '');
        $lookup = [];
        foreach ($definitions as $label => $field) {
            $matches = array_values(array_filter($rows, static function (array $row) use ($normalize, $label): bool {
                return $normalize($row[1] ?? '') === $normalize($label);
            }));
            if (count($matches) !== 1) throw new RuntimeException("WA COGP-COGS expected one '{$label}' row, found ".count($matches).'.');
            $lookup[$field] = $matches[0];
        }

        $allocations = [];
        foreach ($periods as $period) {
            $month = (int) substr($period, 5, 2);
            $column = $month + 1; // Column C is January.
            $values = [];
            foreach ($lookup as $field => $row) $values[$field] = $this->number($row[$column] ?? null) ?? 0.0;
            $allocated = $values['allocated_bua_usd'] + $values['allocated_wash_usd'] + $values['allocated_port_usd'] + $values['allocated_isp_usd'];
            if (abs($allocated - $values['overhead_pool_usd']) > 0.02) {
                throw new RuntimeException("{$period}: WA COGP-COGS overhead allocation does not reconcile to its pool.");
            }
            $allocations[] = ['period'=>$period,'source_sheet'=>'WA COGP-COGS'] + $values;
        }
        return $allocations;
    }

    /** Parse the live WA COGP-COGS roll-forward used by localhost:3000 Internal Cost/MT. */
    private function parseInternalWaPeriods(array $rows, array $periods): array
    {
        $sections = ['allocation'=>[], 'bua'=>[], 'wash'=>[], 'port'=>[], 'isp'=>[]];
        $section = 'allocation';
        $sectionHeadings = [
            'BUA MINING INVENTORY & COGP'=>'bua',
            'WASH PLANT INVENTORY & COGP'=>'wash',
            'PORT INVENTORY, COGP & COGS'=>'port',
            'ISP INVENTORY, COGP & COGS'=>'isp',
            'TOTAL COGS & INVENTORY CONTROL'=>'end',
        ];
        foreach ($rows as $row) {
            $label = trim((string) ($row[1] ?? ''));
            foreach ($sectionHeadings as $heading=>$target) if (mb_strtolower($label) === mb_strtolower($heading)) { $section = $target; break; }
            if (array_key_exists($section, $sections)) $sections[$section][] = $row;
        }
        $normalize = static fn (mixed $value): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '');
        $row = static function (string $area, string $label) use (&$sections, $normalize): array {
            $matches = array_values(array_filter($sections[$area] ?? [], static fn (array $item): bool => $normalize($item[1] ?? '') === $normalize($label)));
            if (count($matches) !== 1) throw new RuntimeException("WA COGP-COGS expected one '{$label}' row in {$area}, found ".count($matches).'.');
            return $matches[0];
        };
        $definitions = [
            'coalGetting'=>['bua','Production qty'], 'buaToWP'=>['bua','Raw transfer to WP qty'], 'buaToPort'=>['bua','Direct transfer to Port qty'],
            'wpReceipt'=>['wash','Raw BUA transfer qty'], 'washedCoalProduction'=>['wash','Wash coal production qty'], 'wpToPort'=>['wash','Transfer to Port qty'],
            'portReceiptBua'=>['port','Direct receipt from BUA qty'], 'portReceiptWp'=>['port','Receipt from WP qty'], 'portToIsp'=>['port','Transfer to ISP qty'],
            'portSales'=>['port','FOB Port sales qty'], 'ispReceipt'=>['isp','Receipt from Port qty'], 'ispSales'=>['isp','FOB ISP sales / move-out qty'],
            'ispHistoricalStockAdjustment'=>['isp','Adjusment Stock'],
            'overheadPoolUSD'=>['allocation','OVH JKT & PC + ISP Rent pool'], 'overheadBuaUSD'=>['allocation','OVH & ISP Rent allocated — BUA'],
            'overheadWashUSD'=>['allocation','OVH & ISP Rent allocated — WP'], 'overheadPortUSD'=>['allocation','OVH & ISP Rent allocated — Port'],
            'overheadIspUSD'=>['allocation','OVH & ISP Rent allocated — ISP'], 'miningCostUSD'=>['bua','Mining cost excl. corporate OVH'],
            'washActivityCostUSD'=>['wash','WP incremental activity cost'], 'portDirectRouteCostUSD'=>['port','Direct route incremental cost'],
            'portWpRouteCostUSD'=>['port','WP route incremental cost'], 'portLoadingCostUSD'=>['port','Loading BLC / port handling'],
            'ispTransportCostUSD'=>['isp','Port to ISP transhipment cost'], 'portSellingCostUSD'=>['port','Selling cost Port to Taboneo'],
            'ispSellingCostUSD'=>['isp','Selling cost ISP to Taboneo'], 'ispRentUSD'=>['isp','ISP Rent total — allocation pool'],
        ];
        $sourceRows = [];
        foreach ($definitions as $key=>[$area,$label]) $sourceRows[$key] = $row($area, $label);
        $openingBalances = [];
        foreach ([['bua','bua'],['wash','washed'],['port','port'],['isp','kunz']] as [$area,$id]) {
            $qty = $this->number($row($area,'Opening qty')[2] ?? null);
            $value = $this->number($row($area,'Opening amount')[2] ?? null);
            if ($qty === null || $value === null) throw new RuntimeException("WA COGP-COGS opening balance is missing for {$area}.");
            $openingBalances[$id] = ['qtyMT'=>$qty,'valueUSD'=>$value];
        }
        $expectedRows = [];
        foreach ([['bua','bua'],['wash','washed'],['port','port'],['isp','kunz']] as [$area,$id]) {
            $expectedRows[$id] = ['qty'=>$row($area,'Ending qty'),'value'=>$row($area,'Ending amount')];
        }

        $records = [];
        foreach ($periods as $period) {
            $month = (int) substr($period, 5, 2);
            $column = $month + 1; // January is column C in WA COGP-COGS.
            $payload = ['openingBalances'=>$openingBalances];
            foreach ($sourceRows as $key=>$sourceRow) $payload[$key] = $this->number($sourceRow[$column] ?? null) ?? 0.0;
            $signal = ['coalGetting','buaToWP','buaToPort','wpToPort','portToIsp','portSales','ispSales','miningCostUSD','washActivityCostUSD','portLoadingCostUSD','overheadPoolUSD'];
            if (!array_filter($signal, static fn (string $key): bool => abs((float) $payload[$key]) > 1e-9)) continue;
            foreach ([['buaToWP','wpReceipt'],['buaToPort','portReceiptBua'],['wpToPort','portReceiptWp'],['portToIsp','ispReceipt']] as [$out,$in]) {
                if (abs($payload[$out] - $payload[$in]) > 0.01) throw new RuntimeException("{$period}: WA COGP-COGS transfer {$out} does not reconcile with its receiving location.");
            }
            $payload['expectedClosing'] = [];
            foreach ($expectedRows as $id=>$closing) {
                $qty = $this->number($closing['qty'][$column] ?? null);
                $value = $this->number($closing['value'][$column] ?? null);
                if ($qty === null || $value === null) throw new RuntimeException("{$period}: WA COGP-COGS closing balance is missing for {$id}.");
                $payload['expectedClosing'][$id] = ['qtyMT'=>$qty,'valueUSD'=>$value];
            }
            $allocated = $payload['overheadBuaUSD'] + $payload['overheadWashUSD'] + $payload['overheadPortUSD'] + $payload['overheadIspUSD'];
            if (abs($allocated - $payload['overheadPoolUSD']) > 0.02) throw new RuntimeException("{$period}: WA COGP-COGS overhead allocations do not reconcile to their pool.");
            $records[] = ['period'=>$period,'payload'=>$payload];
        }
        if (!$records) throw new RuntimeException('WA COGP-COGS has no monthly activity to synchronize.');
        return $records;
    }

    private function parseDbCostSummary(array $rows, array $periods): array
    {
        $summaryStart = null;
        foreach ($rows as $index => $row) {
            if (strtolower(trim((string) ($row[1] ?? ''))) === 'summary') { $summaryStart = $index + 1; break; }
        }
        if ($summaryStart === null) throw new RuntimeException('Google Sheets DB tab tidak memiliki bagian Summary.');

        $result = [];
        foreach (array_slice($rows, $summaryStart) as $row) {
            $activity = trim((string) ($row[2] ?? ''));
            $currency = strtoupper(trim((string) ($row[3] ?? '')));
            $entity = trim((string) ($row[4] ?? ''));
            if ($activity === '' || $currency !== 'USD' || $entity === '') break;
            foreach ($periods as $period) {
                $month = (int) substr($period, 5, 2);
                $amount = $this->number($row[7 + $month] ?? null);
                if ($amount === null) continue;
                $identity = json_encode([$entity, $activity], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!isset($result[$period][$identity])) $result[$period][$identity] = ['entity'=>$entity,'activity'=>$activity,'amount'=>0.0];
                $result[$period][$identity]['amount'] += $amount;
            }
        }
        foreach ($result as &$items) $items = array_values($items);
        unset($items);
        return $result;
    }

    /** Read the monthly cost categories from the live DB sheet, not a cached JSON snapshot. */
    private function parseDbWashCostCategories(array $rows, array $periods, array $dbCostSummary): array
    {
        $start = null;
        foreach ($rows as $index => $row) {
            if (strcasecmp(trim((string) ($row[1] ?? '')), 'BP Wash Plant Cost') === 0) {
                $start = $index + 1;
                break;
            }
        }
        if ($start === null) return [];

        $end = null;
        foreach ($rows as $index => $row) {
            if ($index >= $start && strcasecmp(trim((string) ($row[2] ?? '')), 'Total BP Wash Plant Cost') === 0) {
                $end = $index;
                break;
            }
        }
        if ($end === null) throw new RuntimeException('Google Sheets DB tab: BP Wash Plant Cost block has no total row.');

        $result = [];
        foreach (array_slice($rows, $start, $end - $start) as $row) {
            $category = trim((string) ($row[2] ?? ''));
            if ($category === '' || strtoupper(trim((string) ($row[3] ?? ''))) !== 'USD') continue;
            foreach ($periods as $period) {
                $month = (int) substr($period, 5, 2);
                $amount = $this->number($row[7 + $month] ?? null) ?? 0.0;
                $key = $period.'|'.$category;
                if (!isset($result[$key])) $result[$key] = [
                    'period'=>$period,'category'=>$category,'amount_usd'=>0.0,'source_sheet'=>'DB',
                    'created_at'=>now(),'updated_at'=>now(),
                ];
                $result[$key]['amount_usd'] += $amount;
            }
        }

        $categories = array_values($result);
        $totals = [];
        foreach ($categories as $category) $totals[$category['period']] = ($totals[$category['period']] ?? 0.0) + (float) $category['amount_usd'];
        foreach ($totals as $period => $amount) {
            $summary = collect($dbCostSummary[$period] ?? [])->first(static fn (array $row): bool =>
                strcasecmp((string) ($row['entity'] ?? ''), 'BP') === 0 && strcasecmp((string) ($row['activity'] ?? ''), 'Washplant') === 0
            );
            if ($summary && abs($amount - (float) $summary['amount']) > 0.02) {
                throw new RuntimeException("{$period}: Google Sheets BP Wash Plant Cost categories do not reconcile to DB Summary.");
            }
            if (!$summary && abs($amount) > 0.02) {
                throw new RuntimeException("{$period}: Google Sheets BP Wash Plant Cost categories have no matching BP / Washplant DB Summary total.");
            }
        }

        return $categories;
    }

    /** Parse the DB sheet's Mining Operation Cost section into report accrual rows. */
    private function parseDbAccrualItems(array $rows, array $periods, array $monthlyMetrics): array
    {
        $accountMap = [
            '5000404'=>['name'=>'OB Removal','pattern'=>'/^(OB Removal|OB Overdistance|Topsoil|Mud)/i','qtyLabel'=>'Total Waste removal','unit'=>'Bcm'],
            '5000405'=>['name'=>'Coal Getting','pattern'=>'/^Coal Getting/i','qtyLabel'=>'Coal Getting','unit'=>'MT'],
            '5000406'=>['name'=>'Coal Hauling pit to BUA/stockpile','pattern'=>'/^Coal Hauling Overdistance/i','qtyLabel'=>'Coal Getting','unit'=>'MT'],
            '5000407'=>['name'=>'Coal Hauling BUA to WP','pattern'=>'/^Coal Hauling ROM BUA to WP/i','qtyLabel'=>'Coal Hauling BUA To Wash Plant','unit'=>'MT'],
            '5000408'=>['name'=>'Coal Hauling WP to Port','pattern'=>'/^Coal Hauling WP to Port/i','qtyLabel'=>'Coal hauling Wash Plant to Port','unit'=>'MT'],
            '5000409'=>['name'=>'Coal hauling BUA to Port','pattern'=>'/^Coal Hauling ROM BUA to Port/i','qtyLabel'=>'Coal Hauling Bua Stock to Port','unit'=>'MT'],
            '5000410'=>['name'=>'Road Maintenance','pattern'=>'/^Road Maintenance/i','qtyLabel'=>null,'unit'=>'MT'],
            '5000411'=>['name'=>'Coal Loading','pattern'=>'/^Coal Loading/i','qtyLabel'=>null,'unit'=>'MT'],
        ];
        $qtyLookup = [];
        foreach ($rows as $row) {
            $label = strtolower(trim((string) ($row[2] ?? '')));
            $entity = strtolower(trim((string) ($row[4] ?? '')));
            $unit = strtolower(trim((string) ($row[3] ?? '')));
            foreach ($accountMap as $code => $map) {
                $code = (string) $code;
                if ($map['qtyLabel'] === null || $label !== strtolower($map['qtyLabel']) || !in_array($unit, ['mt','bcm'], true)) continue;
                if ($code === '5000404' ? ($entity !== '' && $entity !== 'hrs') : ($entity === '' || $entity === 'bp')) continue;
                $qtyEntity = $code === '5000404' ? 'hrs' : $entity;
                foreach ($periods as $period) {
                    $month = (int) substr($period, 5, 2);
                    $value = $this->number($row[7 + $month] ?? null);
                    if ($value !== null) $qtyLookup[$code][$period][$qtyEntity] = ($qtyLookup[$code][$period][$qtyEntity] ?? 0) + $value;
                }
            }
            if (in_array($label, ['total loading bua to wash plant','total loading bua stock to port','total loading wash plant to port'], true) && in_array($unit, ['mt','bcm'], true)) {
                $loadingCategory = match ($label) {
                    'total loading bua to wash plant' => 'Coal Hauling BUA to WP',
                    'total loading bua stock to port' => 'Coal Hauling BUA to Port',
                    default => 'Coal Hauling WP to Port',
                };
                if ($entity !== '' && strcasecmp($entity, 'BP') !== 0) foreach ($periods as $period) {
                    $month = (int) substr($period, 5, 2);
                    $value = $this->number($row[7 + $month] ?? null);
                    if ($value !== null) $qtyLookup['5000411'][$period][$loadingCategory][$entity] = ($qtyLookup['5000411'][$period][$loadingCategory][$entity] ?? 0) + $value;
                }
            }
        }

        $items = [];
        $inMiningSection = false;
        foreach ($rows as $row) {
            $section = trim((string) ($row[1] ?? ''));
            if (strcasecmp($section, 'Mining Operation Cost') === 0) $inMiningSection = true;
            elseif (strcasecmp($section, 'Fuel Mining Operation Cost') === 0) $inMiningSection = false;
            if (!$inMiningSection) continue;

            $label = trim((string) ($row[2] ?? ''));
            $entity = trim((string) ($row[4] ?? ''));
            $code = null; $map = null;
            foreach ($accountMap as $candidate => $definition) {
                if (preg_match($definition['pattern'], $label)) { $code = (string) $candidate; $map = $definition; break; }
            }
            if ($code === null || $entity === '' || strcasecmp($entity, 'BP') === 0) continue;

            $category = 'Mining';
            if ($code === '5000407' || ($code === '5000411' && preg_match('/(?:ROM|BUA).*to (?:WP|Wash(?:ing)? Plant)/i', $label))) $category = 'Coal Hauling BUA to WP';
            elseif ($code === '5000408' || ($code === '5000411' && preg_match('/(?:WP|Wash(?:ing)? Plant).*to Port/i', $label))) $category = 'Coal Hauling WP to Port';
            elseif ($code === '5000409' || ($code === '5000411' && preg_match('/(?:ROM|BUA).*to Port/i', $label))) $category = 'Coal Hauling BUA to Port';
            elseif ($code === '5000411') continue;

            foreach ($periods as $period) {
                $month = (int) substr($period, 5, 2);
                $amount = $this->number($row[7 + $month] ?? null);
                if ($amount === null || abs($amount) < 0.0000001) continue;
                $key = implode('|', [$period,$code,$category,strtolower($entity)]);
                $loadingMetric = match ($category) {
                    'Coal Hauling BUA to WP' => 'loadingBuaWp',
                    'Coal Hauling BUA to Port' => 'loadingBuaPort',
                    'Coal Hauling WP to Port' => 'loadingWpPort',
                    default => null,
                };
                $qty = $code === '5000411'
                    ? ($monthlyMetrics[$period][$loadingMetric] ?? $qtyLookup[$code][$period][$category][strtolower($entity)] ?? null)
                    : ($map['qtyLabel'] === null ? null : ($qtyLookup[$code][$period][strtolower($entity)] ?? null));
                if ($code === '5000404' && strcasecmp($entity, 'HRS') !== 0) $qty = null;
                if (!isset($items[$key])) $items[$key] = [
                    'period'=>$period,'account_code'=>$code,'name'=>$map['name'],'entity'=>$entity,
                    'category'=>$category,'amount_usd'=>0,'qty'=>$qty,'rate_usd'=>null,'unit'=>$map['unit'],
                    'created_at'=>now(),'updated_at'=>now(),
                ];
                $items[$key]['amount_usd'] += $amount;
                if ($qty !== null && abs($qty) > 0.0000001) $items[$key]['rate_usd'] = $items[$key]['amount_usd'] / $qty;
            }
        }
        return array_values($items);
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

    private function findSectionSubheaderColumn(array $headers, array $subheaders, string $section, string $wanted, int $columnOffset): ?int
    {
        $normalize = static fn (mixed $value): string => strtolower(preg_replace('/\s+/', ' ', trim((string) $value)));
        $sectionIndex = null;
        foreach ($headers as $index => $header) if ($normalize($header) === $normalize($section)) { $sectionIndex = $index; break; }
        if ($sectionIndex === null) return null;
        $end = count($headers);
        for ($index = $sectionIndex + 1; $index < count($headers); $index++) {
            if ($normalize($headers[$index] ?? '') !== '') { $end = $index; break; }
        }
        foreach ($subheaders as $index => $header) {
            if ($index >= $sectionIndex && $index < $end && $normalize($header) === $normalize($wanted)) return $index + 1 - $columnOffset;
        }
        return null;
    }

    private function columnLabel(int $column): string
    {
        $label = '';
        while ($column > 0) {
            $column--;
            $label = chr(65 + ($column % 26)).$label;
            $column = intdiv($column, 26);
        }
        return $label;
    }

    private function date(mixed $value): ?string
    {
        if (is_numeric($value)) {
            if ((float) $value <= 31) return null;
            return gmdate('Y-m-d', (int) (($value - 25569) * 86400));
        }
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
