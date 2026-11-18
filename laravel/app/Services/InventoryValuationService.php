<?php

namespace App\Services;

use App\Models\InventoryManualInput;
use App\Models\InventoryOpening;
use App\Models\GlEntry;
use Illuminate\Support\Facades\DB;

class InventoryValuationService
{
    public function build(string $period, string $mode = 'mtd'): array
    {
        if ($period >= '2026-10' && in_array($mode, ['mtd', 'ytd'], true)) {
            $dynamic = $this->buildLiveFromSources($period, $mode);
            if ($dynamic !== null) return $mode === 'ytd' ? $dynamic : $this->applyManualInputs($dynamic, $period, $mode, ['sale']);
            throw new \RuntimeException("{$period} inventory overview requires live opening balances, Google Sheets movements, and imported D365 GL data. Baseline data is not used from October 2026 onward.");
        }
        $baseline = base_path("../baseline/reference_dump/inventory-valuation_{$period}_{$mode}.json");
        if (is_file($baseline)) {
            $data = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR)['data'];
            return $this->applyManualInputs($data, $period, $mode);
        }
        $source = base_path('../data_snapshot/extracted/inventory_movements.json');
        $movements = is_file($source) ? json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR)['periods'] ?? [] : [];
        $balances = [];
        foreach (InventoryOpening::all() as $opening) {
            $balances[$opening->location] = ['location' => $opening->location, 'qtyMT' => (float) $opening->qty_mt, 'valueUSD' => (float) $opening->value_usd];
        }
        foreach ($movements as $month => $data) {
            if ($month > $period) break;
            $this->add($balances, 'bua', $data['coalMinedMT'] ?? 0, $data['buaAdjustments']['coalProduced']['valueUSD'] ?? 0);
            $this->move($balances, 'bua', 'nonWash', $data['buaToNonWashMT'] ?? 0);
            $this->move($balances, 'bua', 'washed', $data['washFeedMT'] ?? 0);
            $this->move($balances, 'bua', 'port', $data['buaToPortMT'] ?? 0);
            $this->move($balances, 'nonWash', 'port', $data['nonWashToPortMT'] ?? 0);
            $this->move($balances, 'washed', 'port', $data['washedToPortMT'] ?? 0);
            $this->add($balances, 'washed', -($data['washRejectMT'] ?? 0));
            $this->add($balances, 'highAsh', ($data['washRejectToHighAshMT'] ?? 0) - ($data['highAshDisposalMT'] ?? 0));
            $this->add($balances, 'port', -($data['portSalesMT'] ?? 0) - ($data['portToMgmMT'] ?? 0) - ($data['portToKunzMT'] ?? 0));
            $this->add($balances, 'kunz', $data['portToKunzMT'] ?? 0);
            $this->add($balances, 'kunz', -($data['kunzSalesMT'] ?? 0));
        }
        foreach ($balances as &$balance) $balance['costPerMT'] = $balance['qtyMT'] == 0.0 ? null : $balance['valueUSD'] / $balance['qtyMT'];
        return $this->applyManualInputs(['currency' => 'USD', 'period' => $period, 'dataSource' => 'workbook', 'locations' => array_values($balances)], $period, $mode);
    }

    /** Roll inventory forward from the September historical close using only live month data. */
    private function buildLiveFromSources(string $period, string $mode): ?array
    {
        $cutover = '2026-10';
        if ($period < $cutover) return null;
        if ($period === $cutover) {
            $september = base_path('../baseline/reference_dump/inventory-valuation_2026-09_mtd.json');
            if (!is_file($september)) return null;
            $openingData = json_decode(file_get_contents($september), true, 512, JSON_THROW_ON_ERROR)['data'];
        } else {
            $previousPeriod = date('Y-m', strtotime($period.'-01 -1 month'));
            $openingData = $this->buildLiveFromSources($previousPeriod, 'mtd');
            if ($openingData === null) return null;
            $openingData = $this->applyManualInputs($openingData, $previousPeriod, 'mtd', ['sale']);
        }
        $month = $this->buildLiveMonthFromSources($period, $openingData);
        if ($month === null || $mode === 'mtd') return $month;
        return $this->buildLiveYtdFromSources($period, $month);
    }

    private function buildLiveMonthFromSources(string $period, array $openingData): ?array
    {
        $template = $openingData;
        $movementRows = DB::table('inventory_movements')->where('period', $period)->where('source', 'google_sheets')->get();
        $productionRows = DB::table('daily_production')->where('period', $period)->where('source', 'google_sheets')->count();
        $glRows = GlEntry::where('period', $period)->where('source_type', 'd365')->count();
        if ($movementRows->isEmpty() || $productionRows === 0 || $glRows === 0) return null;

        // Inventory costing in the reference uses only synced GL rows; monthly
        // DB-derived auto accruals belong in Report Cost/Financial Statements.
        $cost = app(CostMatrixService::class)->build($period, 'mtd', false)['grandTotal'];
        $costs = [
            'bua' => (float) ($cost['costMining'] ?? 0),
            'nonWash' => (float) ($cost['costHaulingBUAToWP'] ?? 0),
            'washed' => (float) ($cost['costWP'] ?? 0),
            'port' => (float) ($cost['costHaulingBUAToPort'] ?? 0) + (float) ($cost['costHaulingWPToPort'] ?? 0) + (float) ($cost['costPort'] ?? 0),
        ];
        $opening = collect($openingData['stages'] ?? [])->keyBy('id');
        $stages = collect($template['stages'] ?? [])->keyBy('id');
        // Route quantities that have left BUA/Wash but have not reached BP PORT
        // are still company inventory. Keep them as their own visible locations
        // so totals and the composition chart include them exactly once.
        foreach ([
            'transitBuaPort' => 'In Transit BUA to Port',
            'transitWpPort' => 'In Transit WP to Port',
        ] as $id => $name) {
            if (!$stages->has($id)) {
                $prototype = $stages->get('transitPort') ?? $stages->get('port') ?? [];
                $stages->put($id, array_merge($prototype, [
                    'id' => $id,
                    'name' => $name,
                    'location' => $name,
                    'includedInQuantityTotal' => true,
                    'includedInValueTotal' => true,
                ]));
            }
        }
        $qty = $amount = [];
        foreach ($stages as $id => $stage) {
            $prior = $opening->get($id);
            $qty[$id] = (float) ($prior['endingQtyMT'] ?? 0);
            $amount[$id] = (float) ($prior['endingValueUSD'] ?? 0);
        }
        $transitOpenings = $openingData['liveTransitBalances'] ?? [];
        foreach (['transitBuaPort','transitWpPort'] as $id) {
            $qty[$id] = (float) ($transitOpenings[$id]['qtyMT'] ?? 0);
            $amount[$id] = (float) ($transitOpenings[$id]['valueUSD'] ?? 0);
        }
        $availableSnapshots = [];
        $activity = [
            'bua'=>['in'=>0.0,'receipt'=>0.0,'cost'=>0.0,'out'=>0.0,'outValue'=>0.0,'adjust'=>0.0,'adjustValue'=>0.0],
            'nonWash'=>['in'=>0.0,'receipt'=>0.0,'cost'=>0.0,'out'=>0.0,'outValue'=>0.0,'adjust'=>0.0,'adjustValue'=>0.0],
            'washed'=>['in'=>0.0,'receipt'=>0.0,'cost'=>0.0,'out'=>0.0,'outValue'=>0.0,'adjust'=>0.0,'adjustValue'=>0.0],
            'highAsh'=>['in'=>0.0,'receipt'=>0.0,'cost'=>0.0,'out'=>0.0,'outValue'=>0.0,'adjust'=>0.0,'adjustValue'=>0.0],
            'port'=>['in'=>0.0,'receipt'=>0.0,'cost'=>0.0,'out'=>0.0,'outValue'=>0.0,'adjust'=>0.0,'adjustValue'=>0.0],
            'transitBuaWp'=>['in'=>0.0,'receipt'=>0.0,'cost'=>0.0,'out'=>0.0,'outValue'=>0.0,'adjust'=>0.0,'adjustValue'=>0.0],
            'transitBuaPort'=>['in'=>0.0,'receipt'=>0.0,'cost'=>0.0,'out'=>0.0,'outValue'=>0.0,'adjust'=>0.0,'adjustValue'=>0.0],
            'transitWpPort'=>['in'=>0.0,'receipt'=>0.0,'cost'=>0.0,'out'=>0.0,'outValue'=>0.0,'adjust'=>0.0,'adjustValue'=>0.0],
        ];
        foreach ($stages as $id => $_stage) $activity[$id] ??= ['in'=>0.0,'receipt'=>0.0,'cost'=>0.0,'out'=>0.0,'outValue'=>0.0,'adjust'=>0.0,'adjustValue'=>0.0];
        $manualAdjustments = InventoryManualInput::query()
            ->where('period', $period)->where('status', 'active')->where('input_type', 'adjustment')
            ->orderBy('id')->get()->groupBy('location');
        $manualRows = [];
        $closingAdjustmentLocations = array_fill_keys(['bua','nonWash','washed','port'], true);
        $closingAdjustments = [];
        $applyManualAdjustment = function (string $id) use (&$qty, &$amount, &$activity, &$manualRows, &$closingAdjustments, $closingAdjustmentLocations, $manualAdjustments): void {
            foreach ($manualAdjustments->get($id, collect()) as $input) {
                $adjustmentQty = (float) $input->qty_mt;
                $adjustmentValue = (float) ($input->adjustment_usd ?? 0);
                $qty[$id] = ($qty[$id] ?? 0.0) + $adjustmentQty;
                $amount[$id] = ($amount[$id] ?? 0.0) + $adjustmentValue;
                $activity[$id]['adjust'] += $adjustmentQty;
                $activity[$id]['adjustValue'] += $adjustmentValue;
                if (isset($closingAdjustmentLocations[$id])) {
                    $closingAdjustments[$id]['qty'] = ($closingAdjustments[$id]['qty'] ?? 0.0) + $adjustmentQty;
                    $closingAdjustments[$id]['value'] = ($closingAdjustments[$id]['value'] ?? 0.0) + $adjustmentValue;
                }
                $manualRows[$id][] = ['label'=>'Manual adjustment · '.$input->reference,'qtyMT'=>$adjustmentQty,'valueUSD'=>$adjustmentValue,'rateUSD'=>abs($adjustmentQty) > 0.000001 ? $adjustmentValue / $adjustmentQty : null,'type'=>'adjustment','source'=>'manual_input'];
            }
        };

        $sum = fn (callable $filter): float => (float) $movementRows->filter($filter)->sum(fn ($row) => (float) $row->qty_mt);
        $routeQty = function (string $location, string $route, string $type) use ($movementRows): float {
            return (float) $movementRows->filter(function ($row) use ($location, $route, $type): bool {
                $meta = json_decode((string) $row->metadata, true) ?: [];
                return $row->location === $location && $row->movement_type === $type && ($meta['route'] ?? '') === $route;
            })->sum(fn ($row) => abs((float) $row->qty_mt));
        };
        $sheetCoalGetting = DB::table('monthly_metrics')->where('metric_key', 'coalGetting')->where('period', $period)->value('value');
        $mined = max(0, $sheetCoalGetting !== null ? (float) $sheetCoalGetting : $sum(fn ($r) => $r->location === 'bua' && $r->movement_type === 'coalMined'));
        // The same route quantity is recorded once at each location: out at BUA,
        // in at Non Wash. It is a single transfer in the stock flow.
        $buaToWp = $routeQty('bua', 'BUA to Washing Plant', 'transfer_out');
        $buaLoadedPort = $routeQty('bua', 'BUA to Port', 'transfer_out');
        $buaArrivedPort = $routeQty('transitBuaPort', 'BUA to Port', 'transfer_out');
        $wpLoadedPort = $routeQty('washed', 'Washing Plant to Port', 'transfer_out');
        $wpArrivedPort = $routeQty('transitWpPort', 'Washing Plant to Port', 'transfer_out');
        $feed = max(0, -$sum(fn ($r) => $r->location === 'nonWash' && $r->movement_type === 'washFeed'));
        $sheetClean = DB::table('monthly_metrics')->where('metric_key', 'washCoal')->where('period', $period)->value('value');
        if ($sheetClean === null) {
            $sheetClean = DB::table('production_activity_details')->whereBetween('production_date', [$period.'-01', date('Y-m-t', strtotime($period.'-01'))])
                ->where('source', 'google_sheets')->where('activity', 'Coal Wash')->where('metric', 'Clean Cooking')->sum('value');
        }
        $clean = max(0, $sheetClean !== null ? (float) $sheetClean : $sum(fn ($r) => $r->location === 'washed' && $r->movement_type === 'washOutput'));
        $reject = max(0, $sum(fn ($r) => $r->location === 'highAsh' && $r->movement_type === 'washReject'));
        $barging = max(0, -$sum(fn ($r) => $r->location === 'port' && $r->movement_type === 'barging'));
        $directBuaWpIn = $routeQty('nonWash', 'BUA to Washing Plant', 'transfer_in');
        $qty['bua'] += $mined;
        $amount['bua'] += $costs['bua'];
        $activity['bua']['in'] = $mined; $activity['bua']['receipt'] = $costs['bua'];
        $availableSnapshots['bua'] = ['qty'=>$qty['bua'],'value'=>$amount['bua']];
        $buaRate = $qty['bua'] > 0 ? $amount['bua'] / $qty['bua'] : 0;
        $buaOut = $buaToWp + $buaLoadedPort;
        $activity['bua']['out'] = $buaOut; $activity['bua']['outValue'] = $buaOut * $buaRate;
        $amount['bua'] -= $activity['bua']['outValue'];
        $qty['bua'] -= $buaOut;
        $applyManualAdjustment('bua');

        // BUA→Non Wash is a direct stockpile transfer, not a separate transit stage.
        $qty['transitBuaWp'] = 0.0;
        $amount['transitBuaWp'] = 0.0;
        $qty['nonWash'] += $directBuaWpIn;
        $amount['nonWash'] += $directBuaWpIn * $buaRate + $costs['nonWash'];
        $activity['nonWash']['in'] = $directBuaWpIn; $activity['nonWash']['receipt'] = $directBuaWpIn * $buaRate; $activity['nonWash']['cost'] = $costs['nonWash'];
        $nonWashAvailable = $qty['nonWash'];
        $nonWashRate = $qty['nonWash'] > 0 ? $amount['nonWash'] / $qty['nonWash'] : 0;
        $availableSnapshots['nonWash'] = ['qty'=>$qty['nonWash'],'value'=>$amount['nonWash']];
        // Preserve the full reported feed. A shortfall must remain visible as negative stock.
        $washFeedApplied = $feed;
        $feedValue = $washFeedApplied * $nonWashRate;
        $qty['nonWash'] -= $washFeedApplied;
        $amount['nonWash'] -= $feedValue;
        $activity['nonWash']['out'] = $washFeedApplied; $activity['nonWash']['outValue'] = $feedValue;
        $applyManualAdjustment('nonWash');

        $qty['washed'] += $feed;
        // Carry the feed value and Washing Plant cost into the Wash moving-average pool.
        $amount['washed'] += $feedValue + $costs['washed'];
        $activity['washed']['in'] = $feed; $activity['washed']['receipt'] = $feedValue; $activity['washed']['cost'] = $costs['washed'];
        $availableSnapshots['washed'] = ['qty'=>$qty['washed'],'value'=>$amount['washed']];
        $washedRate = $qty['washed'] > 0 ? $amount['washed'] / $qty['washed'] : 0;
        $rejectValue = $reject * $washedRate;
        $qty['washed'] -= $reject;
        $amount['washed'] -= $rejectValue;
        $qty['highAsh'] += $reject;
        $amount['highAsh'] += $rejectValue;
        $activity['washed']['out'] = $reject;
        $activity['washed']['outValue'] = $rejectValue;
        $activity['highAsh']['in'] = $reject;
        $activity['highAsh']['receipt'] = $rejectValue;
        $washedOut = $wpLoadedPort;
        $washedValue = $washedOut * $washedRate;
        $qty['washed'] -= $washedOut;
        $amount['washed'] -= $washedValue;
        $activity['washed']['out'] += $washedOut; $activity['washed']['outValue'] += $washedValue;
        $applyManualAdjustment('washed');

        $qty['transitBuaPort'] += $buaLoadedPort;
        $amount['transitBuaPort'] += $buaLoadedPort * $buaRate;
        $activity['transitBuaPort']['in'] = $buaLoadedPort; $activity['transitBuaPort']['receipt'] = $buaLoadedPort * $buaRate;
        $applyManualAdjustment('transitBuaPort');
        $transitBuaRate = $qty['transitBuaPort'] > 0 ? $amount['transitBuaPort'] / $qty['transitBuaPort'] : 0;
        $transitBuaPortValue = $buaArrivedPort * $transitBuaRate;
        $qty['transitBuaPort'] -= $buaArrivedPort; $amount['transitBuaPort'] -= $transitBuaPortValue;
        $activity['transitBuaPort']['out'] = $buaArrivedPort; $activity['transitBuaPort']['outValue'] = $transitBuaPortValue;
        $qty['transitWpPort'] += $wpLoadedPort;
        $amount['transitWpPort'] += $wpLoadedPort * $washedRate;
        $activity['transitWpPort']['in'] = $wpLoadedPort; $activity['transitWpPort']['receipt'] = $wpLoadedPort * $washedRate;
        $applyManualAdjustment('transitWpPort');
        $transitWpRate = $qty['transitWpPort'] > 0 ? $amount['transitWpPort'] / $qty['transitWpPort'] : 0;
        $transitWpPortValue = $wpArrivedPort * $transitWpRate;
        $qty['transitWpPort'] -= $wpArrivedPort; $amount['transitWpPort'] -= $transitWpPortValue;
        $activity['transitWpPort']['out'] = $wpArrivedPort; $activity['transitWpPort']['outValue'] = $transitWpPortValue;
        $portAvailableQty = $qty['port'] + $buaArrivedPort + $wpArrivedPort;
        $portAvailableValue = $amount['port'] + $transitBuaPortValue + $transitWpPortValue + $costs['port'];
        $qty['port'] = $portAvailableQty;
        $amount['port'] = $portAvailableValue;
        $activity['port']['in'] = $buaArrivedPort + $wpArrivedPort; $activity['port']['receipt'] = $transitBuaPortValue + $transitWpPortValue; $activity['port']['cost'] = $costs['port'];
        $portAvailableQty = $qty['port'];
        $portAvailableValue = $amount['port'];
        $availableSnapshots['port'] = ['qty'=>$portAvailableQty,'value'=>$portAvailableValue];
        $portRate = $portAvailableQty > 0 ? $portAvailableValue / $portAvailableQty : 0;
        $bargingValue = $barging * $portRate;
        $qty['port'] = $portAvailableQty - $barging;
        $amount['port'] = $portAvailableValue - $bargingValue;
        $activity['port']['out'] = $barging; $activity['port']['outValue'] = $bargingValue;
        $applyManualAdjustment('port');

        foreach (['highAsh','kunz','transitPort','transitKunz','transitBuaWp'] as $id) $applyManualAdjustment($id);

        foreach ($stages as $id => $stage) {
            $begin = $opening->get($id);
            $transitOpening = $transitOpenings[$id] ?? [];
            $beginQty = (float) ($begin['endingQtyMT'] ?? $transitOpening['qtyMT'] ?? 0);
            $beginValue = (float) ($begin['endingValueUSD'] ?? $transitOpening['valueUSD'] ?? 0);
            $stage['beginningQtyMT'] = $beginQty;
            $stage['beginningValueUSD'] = $beginValue;
            $stage['endingQtyMT'] = $qty[$id] ?? 0.0;
            $stage['endingValueUSD'] = $amount[$id] ?? 0.0;
            $stage['endingRateUSD'] = abs($stage['endingQtyMT']) > 0.000001 ? $stage['endingValueUSD'] / $stage['endingQtyMT'] : 0;
            $stage['movingAverageRateUSD'] = $stage['endingRateUSD'];
            $flow = $activity[$id] ?? ['in'=>0,'receipt'=>0,'cost'=>0,'out'=>0,'outValue'=>0,'adjust'=>0,'adjustValue'=>0];
            $stage['inflowQtyMT'] = $flow['in']; $stage['receiptValueUSD'] = $flow['receipt']; $stage['addedCostUSD'] = $flow['cost'];
            $stage['outflowQtyMT'] = $flow['out']; $stage['outflowValueUSD'] = $flow['outValue']; $stage['adjustmentQtyMT'] = $flow['adjust']; $stage['adjustmentValueUSD'] = $flow['adjustValue'];
            $stage['closingSurveyAdjustmentQtyMT'] = (float) ($closingAdjustments[$id]['qty'] ?? 0);
            $stage['closingSurveyAdjustmentValueUSD'] = (float) ($closingAdjustments[$id]['value'] ?? 0);
            $availableQty = (float) ($availableSnapshots[$id]['qty'] ?? ($beginQty + $flow['in'] + $flow['adjust']));
            $availableValue = (float) ($availableSnapshots[$id]['value'] ?? ($beginValue + $flow['receipt'] + $flow['cost'] + $flow['adjustValue']));
            $stage['availableQtyMT'] = $availableQty; $stage['availableValueUSD'] = $availableValue;
            $stage['movingAverageRateUSD'] = $availableQty > 0 ? $availableValue / $availableQty : 0;
            $stage['quantitySource'] = 'google-sheets';
            $stage['costSource'] = 'd365-gl';
            $openingRow = ['label'=>'Opening balance','qtyMT'=>$beginQty,'valueUSD'=>$beginValue,'rateUSD'=>$beginQty ? $beginValue / $beginQty : 0,'type'=>'opening'];
            $availableRow = ['label'=>'Available at moving average','qtyMT'=>$availableQty,'valueUSD'=>$availableValue,'rateUSD'=>$stage['movingAverageRateUSD'],'type'=>'available'];
            $closingRow = ['label'=>'Closing balance','qtyMT'=>$stage['endingQtyMT'],'valueUSD'=>$stage['endingValueUSD'],'rateUSD'=>$stage['endingRateUSD'],'type'=>'closing'];
            $movement = static fn (string $label, float $movementQty, float $value, string $type, ?string $source = null): array => array_filter(['label'=>$label,'qtyMT'=>$movementQty,'valueUSD'=>$value,'rateUSD'=>abs($movementQty) > 0.000001 ? abs($value / $movementQty) : null,'type'=>$type,'source'=>$source], static fn ($v) => $v !== null);
            $stage['rows'] = match ($id) {
                'bua' => [$openingRow,$movement('Coal mined — COGP Mining', $mined, $costs['bua'], 'receipt', 'Google Sheets + D365 GL'),$availableRow,$movement('Loaded to Wash Plant',-$buaToWp,-$buaToWp*$buaRate,'outflow','Google Sheets'),$movement('Loaded to BP PORT',-$buaLoadedPort,-$buaLoadedPort*$buaRate,'outflow','Google Sheets'),...($manualRows['bua'] ?? []),$closingRow],
                'nonWash' => [$openingRow,$movement('Received from BUA to Washing Plant',$directBuaWpIn,$directBuaWpIn*$buaRate,'receipt','Google Sheets'),$movement('Add cost — Coal Hauling BUA to WP',0,$costs['nonWash'],'cost','D365 GL'),$availableRow,$movement('Washing Plant Feed',-$washFeedApplied,-$feedValue,'outflow','Google Sheets'),...($manualRows['nonWash'] ?? []),$closingRow],
                'washed' => [$openingRow,$movement('Feed received from Non Wash',$feed,$feedValue,'receipt','Google Sheets'),$movement('Add cost — COGP Washing Plant',0,$costs['washed'],'cost','D365 GL'),$availableRow,$movement('Reject transferred to High Ash',-$reject,-$rejectValue,'outflow','Google Sheets'),$movement('Loaded to BP PORT',-$washedOut,-$washedValue,'outflow','Google Sheets'),...($manualRows['washed'] ?? []),$closingRow],
                'highAsh' => [$openingRow,$movement('Reject received from Wash',$reject,$rejectValue,'receipt','Google Sheets'),$availableRow,...($manualRows['highAsh'] ?? []),$closingRow],
                'port' => [$openingRow,$movement('Received from BUA to Port',$buaArrivedPort,$transitBuaPortValue,'receipt','Google Sheets'),$movement('Received from Washing Plant to Port',$wpArrivedPort,$transitWpPortValue,'receipt','Google Sheets'),$movement('Add cost — hauling routes + COGP BP PORT',0,$costs['port'],'cost','D365 GL'),$availableRow,$movement('Barging',-$barging,-$bargingValue,'outflow','Google Sheets'),...($manualRows['port'] ?? []),$closingRow],
                'transitBuaWp' => [$openingRow,...($manualRows['transitBuaWp'] ?? []),$closingRow],
                'transitBuaPort' => [$openingRow,$movement('Loaded at BUA',$buaLoadedPort,$buaLoadedPort*$buaRate,'receipt','Google Sheets'),...($manualRows['transitBuaPort'] ?? []),$movement('Arrived at BP PORT',-$buaArrivedPort,-$transitBuaPortValue,'outflow','Google Sheets'),$closingRow],
                'transitWpPort' => [$openingRow,$movement('Loaded at Washing Plant',$wpLoadedPort,$wpLoadedPort*$washedRate,'receipt','Google Sheets'),...($manualRows['transitWpPort'] ?? []),$movement('Arrived at BP PORT',-$wpArrivedPort,-$transitWpPortValue,'outflow','Google Sheets'),$closingRow],
                default => [$openingRow,...($manualRows[$id] ?? []),$closingRow],
            };
            $stages->put($id, $stage);
        }
        $included = $stages->filter(fn ($s) => ($s['includedInQuantityTotal'] ?? true) !== false);
        $template['stages'] = $stages->values()->all();
        $template['periodKey'] = $period;
        $template['period'] = $period;
        $template['dataSource'] = 'google-sheets+d365-gl';
        $template['quantitySource'] = 'google-sheets';
        $template['totalEndingQtyMT'] = $included->sum(fn ($s) => (float) $s['endingQtyMT']);
        $template['totalEndingValueUSD'] = $stages->filter(fn ($s) => ($s['includedInValueTotal'] ?? true) !== false)->sum(fn ($s) => (float) $s['endingValueUSD']);
        $template['costMapping'] = ['hasData'=>true,'source'=>'d365-gl','buaUSD'=>$costs['bua'],'washingUSD'=>$costs['washed'],'portUSD'=>(float) ($cost['costPort'] ?? 0),'haulingTotalUSD'=>$costs['nonWash'] + (float) ($cost['costHaulingBUAToPort'] ?? 0) + (float) ($cost['costHaulingWPToPort'] ?? 0),'haulingBUAToWPUSD'=>$costs['nonWash'],'haulingBUAToPortUSD'=>(float) ($cost['costHaulingBUAToPort'] ?? 0),'haulingWPToPortUSD'=>(float) ($cost['costHaulingWPToPort'] ?? 0),'quantityReconciliationMT'=>round($feed-$clean-$reject, 6),'source'=>'D365 GL allocation: COGP Mining, Coal Hauling routes, Washing Plant, BP PORT'];
        $template['dynamicSourceStatus'] = 'active';
        $template['sourceRowCounts'] = ['productionDays'=>$productionRows,'inventoryMovements'=>$movementRows->count(),'d365GlRows'=>$glRows];
        $template['liveMovements'] = compact('mined','buaToWp','buaLoadedPort','buaArrivedPort','wpLoadedPort','wpArrivedPort','feed','clean','reject','barging');
        $template['liveTransitBalances'] = [
            'transitBuaPort'=>['qtyMT'=>(float) ($qty['transitBuaPort'] ?? 0),'valueUSD'=>(float) ($amount['transitBuaPort'] ?? 0)],
            'transitWpPort'=>['qtyMT'=>(float) ($qty['transitWpPort'] ?? 0),'valueUSD'=>(float) ($amount['transitWpPort'] ?? 0)],
        ];
        $template['validationWarnings'] = [];
        if (abs($feed - $clean - $reject) > 0.001) $template['validationWarnings'][] = 'Coal Wash quantity does not reconcile: Feed differs from Clean Cooking plus Reject by '.number_format($feed - $clean - $reject, 3, '.', '').' MT.';
        if ($washedOut > (float) ($opening->get('washed')['endingQtyMT'] ?? 0) + $feed - $reject) $template['validationWarnings'][] = 'Washing Plant to Port loading exceeds Wash stock available for the period; negative stock is retained for reconciliation.';
        $template['history'] = array_values(array_filter($template['history'] ?? [], fn ($r) => ($r['period'] ?? '') !== $period));
        $template['history'][] = ['period'=>$period,'totalEndingQtyMT'=>$template['totalEndingQtyMT'],'totalEndingValueUSD'=>$template['totalEndingValueUSD'],'cogsShippedQtyMT'=>0,'cogsShippedValUSD'=>0,'hasCostData'=>true,'costSource'=>'d365-gl','quantitySource'=>'google-sheets','issues'=>[]];
        $template['buaHistory'] = array_values(array_filter($template['buaHistory'] ?? [], fn ($r) => ($r['period'] ?? '') !== $period));
        $template['buaHistory'][] = $this->buaHistoryRow($stages->get('bua'), $mined, $costs['bua'], $period);
        return $template;
    }

    private function buildLiveYtdFromSources(string $period, array $currentMonth): ?array
    {
        $year = (int) substr($period, 0, 4);
        if ($year === 2026) {
            $ytdPath = base_path('../baseline/reference_dump/inventory-valuation_2026-09_ytd.json');
            if (!is_file($ytdPath)) return null;
            $ytd = json_decode(file_get_contents($ytdPath), true, 512, JSON_THROW_ON_ERROR)['data'];
            $firstLiveMonth = '2026-10';
            $dataSource = 'baseline-through-september+google-sheets+d365-gl';
            $quantitySource = 'baseline-through-september+google-sheets';
        } else {
            $priorDecember = sprintf('%04d-12', $year - 1);
            $ytd = $this->buildLiveFromSources($priorDecember, 'mtd');
            if ($ytd === null) return null;
            $ytd = $this->applyManualInputs($ytd, $priorDecember, 'mtd', ['sale']);
            $ytd['stages'] = array_map(static function (array $stage): array {
                $stage['beginningQtyMT'] = (float) ($stage['endingQtyMT'] ?? 0);
                $stage['beginningValueUSD'] = (float) ($stage['endingValueUSD'] ?? 0);
                foreach (['inflowQtyMT','receiptValueUSD','addedCostUSD','outflowQtyMT','outflowValueUSD','adjustmentQtyMT','adjustmentValueUSD'] as $field) $stage[$field] = 0.0;
                return $stage;
            }, $ytd['stages'] ?? []);
            $ytd['history'] = [];
            $ytd['buaHistory'] = [];
            $firstLiveMonth = sprintf('%04d-01', $year);
            $dataSource = 'live-prior-year-close+google-sheets+d365-gl';
            $quantitySource = 'live-prior-year-close+google-sheets';
        }
        $months = [];
        for ($month = $firstLiveMonth; $month <= $period; $month = date('Y-m', strtotime($month.'-01 +1 month'))) {
            $monthData = $month === $period ? $currentMonth : $this->buildLiveFromSources($month, 'mtd');
            if ($monthData === null) return null;
            $monthData = $this->applyManualInputs($monthData, $month, 'mtd', ['sale']);
            $months[$month] = $monthData;
            if ($month === $period) $currentMonth = $monthData;
        }
        $baselineStages = collect($ytd['stages'] ?? [])->keyBy('id');
        $lastStages = collect($currentMonth['stages'] ?? [])->keyBy('id');
        $flows = ['inflowQtyMT','receiptValueUSD','addedCostUSD','outflowQtyMT','outflowValueUSD','adjustmentQtyMT','adjustmentValueUSD','closingSurveyAdjustmentQtyMT','closingSurveyAdjustmentValueUSD'];
        $ytd['stages'] = array_map(function (array $stage) use ($baselineStages, $lastStages, $months, $flows): array {
            $base = $baselineStages->get($stage['id']);
            $last = $lastStages->get($stage['id']);
            if (!$base || !$last) return $stage;
            $stage['beginningQtyMT'] = (float) ($base['beginningQtyMT'] ?? $base['endingQtyMT'] ?? 0);
            $stage['beginningValueUSD'] = (float) ($base['beginningValueUSD'] ?? $base['endingValueUSD'] ?? 0);
            foreach ($flows as $field) {
                $stage[$field] = (float) ($base[$field] ?? 0);
                foreach ($months as $monthData) {
                    $monthStage = collect($monthData['stages'] ?? [])->firstWhere('id', $stage['id']);
                    $stage[$field] += (float) ($monthStage[$field] ?? 0);
                }
            }
            $stage['endingQtyMT'] = (float) $last['endingQtyMT'];
            $stage['endingValueUSD'] = (float) $last['endingValueUSD'];
            $stage['endingRateUSD'] = $stage['endingQtyMT'] != 0.0 ? $stage['endingValueUSD'] / $stage['endingQtyMT'] : 0;
            $stage['availableQtyMT'] = $stage['beginningQtyMT'] + $stage['inflowQtyMT'] + $stage['adjustmentQtyMT'] - $stage['closingSurveyAdjustmentQtyMT'];
            $stage['availableValueUSD'] = $stage['beginningValueUSD'] + $stage['receiptValueUSD'] + $stage['addedCostUSD'] + $stage['adjustmentValueUSD'] - $stage['closingSurveyAdjustmentValueUSD'];
            $stage['movingAverageRateUSD'] = $stage['availableQtyMT'] > 0 ? $stage['availableValueUSD'] / $stage['availableQtyMT'] : 0;
            $stage['rows'] = [
                ['label'=>'YTD opening balance','qtyMT'=>$stage['beginningQtyMT'],'valueUSD'=>$stage['beginningValueUSD'],'rateUSD'=>$stage['beginningQtyMT'] ? $stage['beginningValueUSD'] / $stage['beginningQtyMT'] : 0,'type'=>'opening'],
                ['label'=>'October onward live movements and allocated costs','qtyMT'=>$stage['endingQtyMT']-$stage['beginningQtyMT'],'valueUSD'=>$stage['endingValueUSD']-$stage['beginningValueUSD'],'rateUSD'=>null,'type'=>'movement','source'=>'Google Sheets + D365 GL'],
                ['label'=>'Closing balance','qtyMT'=>$stage['endingQtyMT'],'valueUSD'=>$stage['endingValueUSD'],'rateUSD'=>$stage['endingRateUSD'],'type'=>'closing'],
            ];
            return $stage;
        }, $currentMonth['stages']);

        $ytd['periodKey'] = $period;
        $ytd['period'] = $period;
        $ytd['dataSource'] = $dataSource;
        $ytd['quantitySource'] = $quantitySource;
        $cost = app(CostMatrixService::class)->build($period, 'ytd', false)['grandTotal'];
        $ytd['costMapping'] = [
            'hasData'=>true,'buaUSD'=>(float) ($cost['costMining'] ?? 0),
            'washingUSD'=>(float) ($cost['costWP'] ?? 0),'portUSD'=>(float) ($cost['costPort'] ?? 0),
            'haulingTotalUSD'=>(float) ($cost['costHaulingBUAToWP'] ?? 0) + (float) ($cost['costHaulingBUAToPort'] ?? 0) + (float) ($cost['costHaulingWPToPort'] ?? 0),
            'haulingBUAToWPUSD'=>(float) ($cost['costHaulingBUAToWP'] ?? 0),
            'haulingBUAToPortUSD'=>(float) ($cost['costHaulingBUAToPort'] ?? 0),
            'haulingWPToPortUSD'=>(float) ($cost['costHaulingWPToPort'] ?? 0),
            'quantityReconciliationMT'=>$currentMonth['costMapping']['quantityReconciliationMT'] ?? null,
            'source'=>'D365 GL allocation: COGP Mining, Coal Hauling routes, Washing Plant, BP PORT',
        ];
        $ytd['totalEndingQtyMT'] = $currentMonth['totalEndingQtyMT'];
        $ytd['totalEndingValueUSD'] = $currentMonth['totalEndingValueUSD'];
        $ytd['dynamicSourceStatus'] = 'active';
        $ytd['sourceRowCounts'] = array_reduce($months, static function (array $totals, array $monthData): array {
            foreach ($monthData['sourceRowCounts'] ?? [] as $key => $value) $totals[$key] = ($totals[$key] ?? 0) + (int) $value;
            return $totals;
        }, []);
        $ytd['liveMovements'] = $currentMonth['liveMovements'];
        $ytd['manualInputs'] = array_merge(...array_values(array_map(static fn (array $data): array => $data['manualInputs'] ?? [], $months)));
        $ytd['manualSalesQtyMT'] = array_sum(array_map(static fn (array $data): float => (float) ($data['manualSalesQtyMT'] ?? 0), $months));
        $ytd['manualSalesRevenueUSD'] = array_sum(array_map(static fn (array $data): float => (float) ($data['manualSalesRevenueUSD'] ?? 0), $months));
        $ytd['validationWarnings'] = array_values(array_unique(array_merge(...array_values(array_map(static fn (array $data): array => $data['validationWarnings'] ?? [], $months)))));
        $ytd['history'] = array_values(array_filter($ytd['history'] ?? [], static fn (array $row): bool => ($row['period'] ?? '') < $firstLiveMonth));
        $ytd['buaHistory'] = array_values(array_filter($ytd['buaHistory'] ?? [], static fn (array $row): bool => ($row['period'] ?? '') < $firstLiveMonth));
        foreach ($months as $month => $monthData) {
            foreach (['history','buaHistory'] as $key) {
                $row = collect($monthData[$key] ?? [])->firstWhere('period', $month);
                if ($row) $ytd[$key][] = $row;
            }
        }
        return $ytd;
    }

    private function buaHistoryRow(?array $stage, float $mined, float $miningCost, string $period): array
    {
        $stage ??= [];
        $stage['period'] = $period;
        $stage['id'] = 'bua';
        $stage['name'] = 'BUA / Mine Site';
        $stage['location'] = 'BUA / Mine Site';
        $stage['costSource'] = 'gl';
        $stage['quantitySource'] = 'google-sheets';
        $stage['rows'] = [
            ['label'=>'Coal mined — COGP BUA','qtyMT'=>$mined,'valueUSD'=>$miningCost,'rateUSD'=>$mined > 0 ? $miningCost / $mined : null,'type'=>'receipt','source'=>'COGP BUA'],
        ];
        return $stage;
    }

    private function applyManualInputs(array $data, string $period, string $mode, ?array $types = null): array
    {
        $yearStart = substr($period, 0, 4).'-01';
        $query = InventoryManualInput::query()->where('status', 'active');
        if ($mode === 'ytd') $query->whereBetween('period', [$yearStart, $period]);
        else $query->where('period', $period);
        if ($types !== null) $query->whereIn('input_type', $types);
        $inputs = $query->orderBy('period')->orderBy('id')->get();
        $stages = collect($data['stages'] ?? [])->keyBy('id');
        $salesQty = 0.0;
        $salesRevenue = 0.0;
        $salesCost = 0.0;
        $inputRows = [];

        foreach ($inputs as $input) {
            $stage = $stages->get($input->location);
            if (!$stage) continue;
            $qty = (float) $input->qty_mt;
            $rate = (float) ($stage['endingRateUSD'] ?? 0);
            $value = (float) $input->adjustment_usd;

            if ($input->input_type === 'sale') {
                $qty = min(max(0, $qty), max(0, (float) ($stage['endingQtyMT'] ?? 0)));
                $value = $qty * $rate;
                $stage['endingQtyMT'] = (float) $stage['endingQtyMT'] - $qty;
                $stage['endingValueUSD'] = (float) $stage['endingValueUSD'] - $value;
                $stage['outflowQtyMT'] = (float) ($stage['outflowQtyMT'] ?? 0) + $qty;
                $stage['outflowValueUSD'] = (float) ($stage['outflowValueUSD'] ?? 0) + $value;
                $salesQty += $qty;
                $salesRevenue += $qty * (float) $input->sale_price_usd_per_mt;
                $salesCost += $value;
                $row = ['label' => 'Manual sale · '.$input->reference, 'qtyMT' => -$qty, 'valueUSD' => -$value, 'rateUSD' => $rate, 'type' => 'outflow', 'source' => 'manual_input'];
            } else {
                $stage['endingQtyMT'] = (float) $stage['endingQtyMT'] + $qty;
                $stage['endingValueUSD'] = (float) $stage['endingValueUSD'] + $value;
                $stage['adjustmentQtyMT'] = (float) ($stage['adjustmentQtyMT'] ?? 0) + $qty;
                $stage['adjustmentValueUSD'] = (float) ($stage['adjustmentValueUSD'] ?? 0) + $value;
                $row = ['label' => 'Manual adjustment · '.$input->reference, 'qtyMT' => $qty, 'valueUSD' => $value, 'rateUSD' => abs($qty) > 0.000001 ? $value / $qty : null, 'type' => 'adjustment', 'source' => 'manual_input'];
            }
            $stage['endingRateUSD'] = abs((float) $stage['endingQtyMT']) > 0.000001 ? (float) $stage['endingValueUSD'] / (float) $stage['endingQtyMT'] : null;
            $stage['rows'] = array_values(array_filter($stage['rows'] ?? [], fn (array $item) => ($item['type'] ?? null) !== 'closing'));
            $stage['rows'][] = $row;
            $stage['rows'][] = ['label' => 'Closing balance after manual inputs', 'qtyMT' => (float) $stage['endingQtyMT'], 'valueUSD' => (float) $stage['endingValueUSD'], 'rateUSD' => $stage['endingRateUSD'], 'type' => 'closing', 'source' => 'manual_input'];
            $inputRows[] = ['id' => $input->id, 'reference' => $input->reference, 'period' => $input->period, 'type' => $input->input_type, 'location' => $input->location, 'qtyMT' => $qty, 'valueUSD' => $value];
            $stages->put($input->location, $stage);
        }

        if ($inputs->isNotEmpty()) {
            $data['stages'] = $stages->values()->all();
            $includedQty = $stages->filter(fn (array $stage) => ($stage['includedInQuantityTotal'] ?? true) !== false)->sum(fn (array $stage) => (float) ($stage['endingQtyMT'] ?? 0));
            $includedValue = $stages->filter(fn (array $stage) => ($stage['includedInValueTotal'] ?? true) !== false)->sum(fn (array $stage) => (float) ($stage['endingValueUSD'] ?? 0));
            $data['totalEndingQtyMT'] = $includedQty;
            $data['totalEndingValueUSD'] = $includedValue;
            foreach ($data['history'] ?? [] as &$historyRow) {
                if (($historyRow['period'] ?? null) === $period) {
                    $historyRow['totalEndingQtyMT'] = $includedQty;
                    $historyRow['totalEndingValueUSD'] = $includedValue;
                    $historyRow['cogsShippedValUSD'] = (float) ($historyRow['cogsShippedValUSD'] ?? 0) + $salesCost;
                }
            }
            unset($historyRow);
        }
        $data['manualInputs'] = $inputRows;
        $data['manualSalesQtyMT'] = $salesQty;
        $data['manualSalesRevenueUSD'] = $salesRevenue;
        $data['manualInputsValuationBasis'] = 'Applied at the baseline closing weighted-average rate; historical transaction timing is not available in snapshots.';
        return $data;
    }

    private function add(array &$balances, string $location, float $qty, float $value = 0): void
    {
        if (!isset($balances[$location])) $balances[$location] = ['location' => $location, 'qtyMT' => 0.0, 'valueUSD' => 0.0];
        $balances[$location]['qtyMT'] += $qty;
        $balances[$location]['valueUSD'] += $value;
    }
    private function move(array &$balances, string $from, string $to, float $qty): void { $this->add($balances, $from, -$qty); $this->add($balances, $to, $qty); }
}
