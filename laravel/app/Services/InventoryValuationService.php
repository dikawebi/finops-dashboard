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
        if ($period >= '2026-10' && $period !== '2026-10') {
            throw new \RuntimeException('Live inventory roll-forward after October 2026 is not available yet. Baseline data is not used from October 2026 onward.');
        }
        if ($period === '2026-10' && in_array($mode, ['mtd', 'ytd'], true)) {
            $dynamic = $this->buildOctoberFromLiveSources($mode);
            if ($dynamic !== null) return $this->applyManualInputs($dynamic, $period, $mode, ['sale']);
            throw new \RuntimeException('October 2026 inventory overview requires Google Sheets production sync and imported D365 GL data. Baseline data is not used for October.');
        }
        $baseline = base_path("../baseline/reference_dump/inventory-valuation_{$period}_{$mode}.json");
        if (is_file($baseline)) {
            $data = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR)['data'];
            if ($period === '2026-10') {
                $data['dynamicSourceStatus'] = 'unavailable';
                $data['validationWarnings'][] = 'October is showing its saved snapshot because current Google Sheets movements or D365 GL data are unavailable. Sync both sources to refresh live valuation.';
            }
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

    /** October 2026 uses live Sheets quantities and D365 GL cost; earlier months remain baseline snapshots. */
    private function buildOctoberFromLiveSources(string $mode): ?array
    {
        $september = base_path('../baseline/reference_dump/inventory-valuation_2026-09_mtd.json');
        if (!is_file($september)) return null;
        // Use the September report only for the opening balance and stable
        // location metadata. October figures are rebuilt from live sources.
        $openingData = json_decode(file_get_contents($september), true, 512, JSON_THROW_ON_ERROR)['data'];
        $template = $openingData;
        $movementRows = DB::table('inventory_movements')->where('period', '2026-10')->where('source', 'google_sheets')->get();
        $productionRows = DB::table('daily_production')->where('period', '2026-10')->where('source', 'google_sheets')->count();
        $glRows = GlEntry::where('period', '2026-10')->where('source_type', 'd365')->count();
        if ($movementRows->isEmpty() || $productionRows === 0 || $glRows === 0) return null;

        // Inventory costing in the reference uses only synced GL rows; monthly
        // DB-derived auto accruals belong in Report Cost/Financial Statements.
        $cost = app(CostMatrixService::class)->build('2026-10', 'mtd', false)['grandTotal'];
        $costs = [
            'bua' => (float) ($cost['costMining'] ?? 0),
            'nonWash' => (float) ($cost['costHaulingBUAToWP'] ?? 0),
            'washed' => (float) ($cost['costWP'] ?? 0),
            'port' => (float) ($cost['costHaulingBUAToPort'] ?? 0) + (float) ($cost['costHaulingWPToPort'] ?? 0) + (float) ($cost['costPort'] ?? 0),
        ];
        $opening = collect($openingData['stages'] ?? [])->keyBy('id');
        $stages = collect($template['stages'] ?? [])->keyBy('id');
        $qty = $amount = [];
        foreach ($stages as $id => $stage) {
            $prior = $opening->get($id);
            $qty[$id] = (float) ($prior['endingQtyMT'] ?? 0);
            $amount[$id] = (float) ($prior['endingValueUSD'] ?? 0);
        }
        foreach (['transitBuaWp','transitBuaPort','transitWpPort'] as $id) { $qty[$id] = 0.0; $amount[$id] = 0.0; }
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
            ->where('period', '2026-10')->where('status', 'active')->where('input_type', 'adjustment')
            ->orderBy('id')->get()->groupBy('location');
        $manualRows = [];
        $applyManualAdjustment = function (string $id) use (&$qty, &$amount, &$activity, &$manualRows, $manualAdjustments): void {
            foreach ($manualAdjustments->get($id, collect()) as $input) {
                $adjustmentQty = (float) $input->qty_mt;
                $adjustmentValue = (float) ($input->adjustment_usd ?? 0);
                $qty[$id] = ($qty[$id] ?? 0.0) + $adjustmentQty;
                $amount[$id] = ($amount[$id] ?? 0.0) + $adjustmentValue;
                $activity[$id]['adjust'] += $adjustmentQty;
                $activity[$id]['adjustValue'] += $adjustmentValue;
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
        $sheetCoalGetting = DB::table('monthly_metrics')->where('metric_key', 'coalGetting')->where('period', '2026-10')->value('value');
        $mined = max(0, $sheetCoalGetting !== null ? (float) $sheetCoalGetting : $sum(fn ($r) => $r->location === 'bua' && $r->movement_type === 'coalMined'));
        // The same route quantity is recorded once at each location: out at BUA,
        // in at Non Wash. It is a single transfer in the stock flow.
        $buaToWp = $routeQty('bua', 'BUA to Washing Plant', 'transfer_out');
        $buaLoadedPort = $routeQty('bua', 'BUA to Port', 'transfer_out');
        $buaArrivedPort = $routeQty('transitBuaPort', 'BUA to Port', 'transfer_out');
        $wpLoadedPort = $routeQty('washed', 'Washing Plant to Port', 'transfer_out');
        $wpArrivedPort = $routeQty('transitWpPort', 'Washing Plant to Port', 'transfer_out');
        $feed = max(0, -$sum(fn ($r) => $r->location === 'nonWash' && $r->movement_type === 'washFeed'));
        $clean = max(0, $sum(fn ($r) => $r->location === 'washed' && $r->movement_type === 'washOutput'));
        $reject = max(0, $sum(fn ($r) => $r->location === 'highAsh' && $r->movement_type === 'washReject'));
        $barging = max(0, -$sum(fn ($r) => $r->location === 'port' && $r->movement_type === 'barging'));
        $directBuaWpIn = $routeQty('nonWash', 'BUA to Washing Plant', 'transfer_in');
        $qty['bua'] += $mined;
        $amount['bua'] += $costs['bua'];
        $activity['bua']['in'] = $mined; $activity['bua']['receipt'] = $costs['bua'];
        $applyManualAdjustment('bua');
        $buaRate = $qty['bua'] > 0 ? $amount['bua'] / $qty['bua'] : 0;
        $buaOut = $buaToWp + $buaLoadedPort;
        $activity['bua']['out'] = $buaOut; $activity['bua']['outValue'] = $buaOut * $buaRate;
        $amount['bua'] -= $activity['bua']['outValue'];
        $qty['bua'] -= $buaOut;

        // BUA→Non Wash is a direct stockpile transfer, not a separate transit stage.
        $qty['transitBuaWp'] = 0.0;
        $amount['transitBuaWp'] = 0.0;
        $qty['nonWash'] += $directBuaWpIn;
        $amount['nonWash'] += $directBuaWpIn * $buaRate + $costs['nonWash'];
        $activity['nonWash']['in'] = $directBuaWpIn; $activity['nonWash']['receipt'] = $directBuaWpIn * $buaRate; $activity['nonWash']['cost'] = $costs['nonWash'];
        $applyManualAdjustment('nonWash');
        $nonWashAvailable = $qty['nonWash'];
        $nonWashRate = $qty['nonWash'] > 0 ? $amount['nonWash'] / $qty['nonWash'] : 0;
        // Preserve the full reported feed. A shortfall must remain visible as negative stock.
        $washFeedApplied = $feed;
        $feedValue = $washFeedApplied * $nonWashRate;
        $qty['nonWash'] -= $washFeedApplied;
        $amount['nonWash'] -= $feedValue;
        $activity['nonWash']['out'] = $washFeedApplied; $activity['nonWash']['outValue'] = $feedValue;

        $qty['washed'] += $feed;
        // The reference moving-average formula carries the feed value into clean output;
        // reject is quantity-only and is tracked separately as High Ash.
        $amount['washed'] += $feedValue + $costs['washed'];
        $activity['washed']['in'] = $feed; $activity['washed']['receipt'] = $feedValue; $activity['washed']['cost'] = $costs['washed']; $activity['washed']['adjust'] = -$reject;
        $qty['washed'] -= $reject;
        $applyManualAdjustment('washed');
        $washedRate = $qty['washed'] > 0 ? $amount['washed'] / $qty['washed'] : 0;
        $washedOut = $wpLoadedPort;
        $washedValue = $washedOut * $washedRate;
        $qty['washed'] -= $washedOut;
        $amount['washed'] -= $washedValue;
        $activity['washed']['out'] = $washedOut; $activity['washed']['outValue'] = $washedValue;
        // Reject reduces Wash stock only; it is not an inflow to High Ash.

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
        $applyManualAdjustment('port');
        $portAvailableQty = $qty['port'];
        $portAvailableValue = $amount['port'];
        $portRate = $portAvailableQty > 0 ? $portAvailableValue / $portAvailableQty : 0;
        $bargingValue = $barging * $portRate;
        $qty['port'] = $portAvailableQty - $barging;
        $amount['port'] = $portAvailableValue - $bargingValue;
        $activity['port']['out'] = $barging; $activity['port']['outValue'] = $bargingValue;

        foreach (['highAsh','kunz','transitPort','transitKunz','transitBuaWp'] as $id) $applyManualAdjustment($id);

        foreach ($stages as $id => $stage) {
            $begin = $opening->get($id);
            $beginQty = (float) ($begin['endingQtyMT'] ?? 0);
            $beginValue = (float) ($begin['endingValueUSD'] ?? 0);
            $stage['beginningQtyMT'] = $beginQty;
            $stage['beginningValueUSD'] = $beginValue;
            $stage['endingQtyMT'] = $qty[$id] ?? 0.0;
            $stage['endingValueUSD'] = $amount[$id] ?? 0.0;
            $stage['endingRateUSD'] = abs($stage['endingQtyMT']) > 0.000001 ? $stage['endingValueUSD'] / $stage['endingQtyMT'] : 0;
            $stage['movingAverageRateUSD'] = $stage['endingRateUSD'];
            $flow = $activity[$id] ?? ['in'=>0,'receipt'=>0,'cost'=>0,'out'=>0,'outValue'=>0,'adjust'=>0,'adjustValue'=>0];
            $stage['inflowQtyMT'] = $flow['in']; $stage['receiptValueUSD'] = $flow['receipt']; $stage['addedCostUSD'] = $flow['cost'];
            $stage['outflowQtyMT'] = $flow['out']; $stage['outflowValueUSD'] = $flow['outValue']; $stage['adjustmentQtyMT'] = $flow['adjust']; $stage['adjustmentValueUSD'] = $flow['adjustValue'];
            $availableQty = $beginQty + $flow['in'] + $flow['adjust'];
            $availableValue = $beginValue + $flow['receipt'] + $flow['cost'] + $flow['adjustValue'];
            $stage['availableQtyMT'] = $availableQty; $stage['availableValueUSD'] = $availableValue;
            $stage['movingAverageRateUSD'] = $availableQty > 0 ? $availableValue / $availableQty : 0;
            $stage['quantitySource'] = 'google-sheets';
            $stage['costSource'] = 'd365-gl';
            $openingRow = ['label'=>'Opening balance','qtyMT'=>$beginQty,'valueUSD'=>$beginValue,'rateUSD'=>$beginQty ? $beginValue / $beginQty : 0,'type'=>'opening'];
            $availableRow = ['label'=>'Available at moving average','qtyMT'=>$availableQty,'valueUSD'=>$availableValue,'rateUSD'=>$stage['movingAverageRateUSD'],'type'=>'available'];
            $closingRow = ['label'=>'Closing balance','qtyMT'=>$stage['endingQtyMT'],'valueUSD'=>$stage['endingValueUSD'],'rateUSD'=>$stage['endingRateUSD'],'type'=>'closing'];
            $movement = static fn (string $label, float $movementQty, float $value, string $type, ?string $source = null): array => array_filter(['label'=>$label,'qtyMT'=>$movementQty,'valueUSD'=>$value,'rateUSD'=>abs($movementQty) > 0.000001 ? abs($value / $movementQty) : null,'type'=>$type,'source'=>$source], static fn ($v) => $v !== null);
            $stage['rows'] = match ($id) {
                'bua' => [$openingRow,$movement('Coal mined — COGP Mining', $mined, $costs['bua'], 'receipt', 'Google Sheets + D365 GL'),...($manualRows['bua'] ?? []),$availableRow,$movement('Loaded to Wash Plant',-$buaToWp,-$buaToWp*$buaRate,'outflow','Google Sheets'),$movement('Loaded to BP PORT',-$buaLoadedPort,-$buaLoadedPort*$buaRate,'outflow','Google Sheets'),$closingRow],
                'nonWash' => [$openingRow,$movement('Received from BUA to Washing Plant',$directBuaWpIn,$directBuaWpIn*$buaRate,'receipt','Google Sheets'),$movement('Add cost — Coal Hauling BUA to WP',0,$costs['nonWash'],'cost','D365 GL'),...($manualRows['nonWash'] ?? []),$availableRow,$movement('Washing Plant Feed',-$washFeedApplied,-$feedValue,'outflow','Google Sheets'),$closingRow],
                'washed' => [$openingRow,$movement('Feed received from Non Wash',$feed,$feedValue,'receipt','Google Sheets'),$movement('Reject (quantity only)',-$reject,0,'adjustment','Google Sheets'),$movement('Add cost — COGP Washing Plant',0,$costs['washed'],'cost','D365 GL'),...($manualRows['washed'] ?? []),$availableRow,$movement('Loaded to BP PORT',-$washedOut,-$washedValue,'outflow','Google Sheets'),$closingRow],
                'highAsh' => [$openingRow,...($manualRows['highAsh'] ?? []),$closingRow],
                'port' => [$openingRow,$movement('Received from BUA to Port',$buaArrivedPort,$transitBuaPortValue,'receipt','Google Sheets'),$movement('Received from Washing Plant to Port',$wpArrivedPort,$transitWpPortValue,'receipt','Google Sheets'),$movement('Add cost — hauling routes + COGP BP PORT',0,$costs['port'],'cost','D365 GL'),...($manualRows['port'] ?? []),$availableRow,$movement('Barging',-$barging,-$bargingValue,'outflow','Google Sheets'),$closingRow],
                'transitBuaWp' => [$openingRow,...($manualRows['transitBuaWp'] ?? []),$closingRow],
                'transitBuaPort' => [$openingRow,$movement('Loaded at BUA',$buaLoadedPort,$buaLoadedPort*$buaRate,'receipt','Google Sheets'),...($manualRows['transitBuaPort'] ?? []),$movement('Arrived at BP PORT',-$buaArrivedPort,-$transitBuaPortValue,'outflow','Google Sheets'),$closingRow],
                'transitWpPort' => [$openingRow,$movement('Loaded at Washing Plant',$wpLoadedPort,$wpLoadedPort*$washedRate,'receipt','Google Sheets'),...($manualRows['transitWpPort'] ?? []),$movement('Arrived at BP PORT',-$wpArrivedPort,-$transitWpPortValue,'outflow','Google Sheets'),$closingRow],
                default => [$openingRow,...($manualRows[$id] ?? []),$closingRow],
            };
            $stages->put($id, $stage);
        }
        $included = $stages->filter(fn ($s) => ($s['includedInQuantityTotal'] ?? true) !== false);
        $template['stages'] = $stages->values()->all();
        $template['periodKey'] = '2026-10';
        $template['period'] = '2026-10';
        $template['dataSource'] = 'google-sheets+d365-gl';
        $template['quantitySource'] = 'google-sheets';
        $template['totalEndingQtyMT'] = $included->sum(fn ($s) => (float) $s['endingQtyMT']);
        $template['totalEndingValueUSD'] = $stages->filter(fn ($s) => ($s['includedInValueTotal'] ?? true) !== false)->sum(fn ($s) => (float) $s['endingValueUSD']);
        $template['costMapping'] = ['hasData'=>true,'source'=>'d365-gl','buaUSD'=>$costs['bua'],'washingUSD'=>$costs['washed'],'portUSD'=>(float) ($cost['costPort'] ?? 0),'haulingTotalUSD'=>$costs['nonWash'] + (float) ($cost['costHaulingBUAToPort'] ?? 0) + (float) ($cost['costHaulingWPToPort'] ?? 0),'haulingBUAToWPUSD'=>$costs['nonWash'],'haulingBUAToPortUSD'=>(float) ($cost['costHaulingBUAToPort'] ?? 0),'haulingWPToPortUSD'=>(float) ($cost['costHaulingWPToPort'] ?? 0),'quantityReconciliationMT'=>round($feed-$clean-$reject, 6),'source'=>'D365 GL allocation: COGP Mining, Coal Hauling routes, Washing Plant, BP PORT'];
        $template['dynamicSourceStatus'] = 'active';
        $template['sourceRowCounts'] = ['productionDays'=>$productionRows,'inventoryMovements'=>$movementRows->count(),'d365GlRows'=>$glRows];
        $template['liveMovements'] = compact('mined','buaToWp','buaLoadedPort','buaArrivedPort','wpLoadedPort','wpArrivedPort','feed','clean','reject','barging');
        $template['validationWarnings'] = [];
        if ($feed > $nonWashAvailable) $template['validationWarnings'][] = 'Google Sheets Washing Plant Feed exceeds opening Non Wash stock plus BUA→WP receipts by '.number_format($feed - $nonWashAvailable, 3, '.', '').' MT; negative closing stock is retained for reconciliation.';
        if (abs($feed - $clean - $reject) > 0.001) $template['validationWarnings'][] = 'Coal Wash quantity does not reconcile: Feed differs from Clean Cooking plus Reject by '.number_format($feed - $clean - $reject, 3, '.', '').' MT.';
        if ($washedOut > (float) ($opening->get('washed')['endingQtyMT'] ?? 0) + $feed - $reject) $template['validationWarnings'][] = 'Washing Plant to Port loading exceeds Wash stock available for the period; negative stock is retained for reconciliation.';
        if ($mode === 'ytd') {
            $ytdPath = base_path('../baseline/reference_dump/inventory-valuation_2026-09_ytd.json');
            $ytd = is_file($ytdPath) ? json_decode(file_get_contents($ytdPath), true, 512, JSON_THROW_ON_ERROR)['data'] : $template;
            $priorYtdStages = collect($ytd['stages'] ?? [])->keyBy('id');
            $template['stages'] = array_map(function (array $stage) use ($priorYtdStages, $activity): array {
                $prior = $priorYtdStages->get($stage['id']);
                if (!$prior) return $stage;
                foreach (['beginningQtyMT','beginningValueUSD'] as $field) $stage[$field] = (float) ($prior[$field] ?? $stage[$field]);
                foreach (['inflowQtyMT','receiptValueUSD','addedCostUSD','outflowQtyMT','outflowValueUSD','adjustmentQtyMT','adjustmentValueUSD'] as $field) $stage[$field] = (float) ($prior[$field] ?? 0) + (float) ($stage[$field] ?? 0);
                $stage['availableQtyMT'] = $stage['beginningQtyMT'] + $stage['inflowQtyMT'] + $stage['adjustmentQtyMT'];
                $stage['availableValueUSD'] = $stage['beginningValueUSD'] + $stage['receiptValueUSD'] + $stage['addedCostUSD'] + $stage['adjustmentValueUSD'];
                $stage['movingAverageRateUSD'] = $stage['availableQtyMT'] > 0 ? $stage['availableValueUSD'] / $stage['availableQtyMT'] : 0;
                $stage['rows'] = [
                    ['label'=>'YTD opening balance','qtyMT'=>$stage['beginningQtyMT'],'valueUSD'=>$stage['beginningValueUSD'],'rateUSD'=>$stage['beginningQtyMT'] ? $stage['beginningValueUSD'] / $stage['beginningQtyMT'] : 0,'type'=>'opening'],
                    ['label'=>'January–October movements and allocated costs','qtyMT'=>$stage['endingQtyMT']-$stage['beginningQtyMT'],'valueUSD'=>$stage['endingValueUSD']-$stage['beginningValueUSD'],'rateUSD'=>null,'type'=>'movement','source'=>'baseline+google-sheets+d365-gl'],
                    ['label'=>'Closing balance','qtyMT'=>$stage['endingQtyMT'],'valueUSD'=>$stage['endingValueUSD'],'rateUSD'=>$stage['endingRateUSD'],'type'=>'closing'],
                ];
                return $stage;
            }, $template['stages']);
            $ytd['stages'] = $template['stages'];
            $ytd['periodKey'] = '2026-10';
            $ytd['period'] = '2026-10';
            $ytd['dataSource'] = 'baseline-jan-sep+google-sheets-oct+d365-gl-oct';
            $ytd['quantitySource'] = 'baseline-jan-sep+google-sheets-oct';
            $ytd['costMapping'] = $template['costMapping'];
            $ytd['totalEndingQtyMT'] = $template['totalEndingQtyMT'];
            $ytd['totalEndingValueUSD'] = $template['totalEndingValueUSD'];
            $ytd['dynamicSourceStatus'] = 'active';
            $ytd['sourceRowCounts'] = $template['sourceRowCounts'];
            $ytd['liveMovements'] = $template['liveMovements'];
            $ytd['validationWarnings'] = $template['validationWarnings'];
            $ytd['history'] = array_values(array_filter($ytd['history'] ?? [], fn ($r) => ($r['period'] ?? '') !== '2026-10'));
            $ytd['history'][] = ['period'=>'2026-10','totalEndingQtyMT'=>$template['totalEndingQtyMT'],'totalEndingValueUSD'=>$template['totalEndingValueUSD'],'cogsShippedQtyMT'=>0,'cogsShippedValUSD'=>0,'hasCostData'=>true,'costSource'=>'d365-gl','quantitySource'=>'google-sheets','issues'=>[]];
            $ytd['buaHistory'] = array_values(array_filter($ytd['buaHistory'] ?? [], fn ($r) => ($r['period'] ?? '') !== '2026-10'));
            $ytd['buaHistory'][] = $this->buaHistoryRow($stages->get('bua'), $mined, $costs['bua']);
            return $ytd;
        }
        $template['history'] = array_values(array_filter($template['history'] ?? [], fn ($r) => ($r['period'] ?? '') !== '2026-10'));
        $template['history'][] = ['period'=>'2026-10','totalEndingQtyMT'=>$template['totalEndingQtyMT'],'totalEndingValueUSD'=>$template['totalEndingValueUSD'],'cogsShippedQtyMT'=>0,'cogsShippedValUSD'=>0,'hasCostData'=>true,'costSource'=>'d365-gl','quantitySource'=>'google-sheets','issues'=>[]];
        $template['buaHistory'] = array_values(array_filter($template['buaHistory'] ?? [], fn ($r) => ($r['period'] ?? '') !== '2026-10'));
        $template['buaHistory'][] = $this->buaHistoryRow($stages->get('bua'), $mined, $costs['bua']);
        return $template;
    }

    private function buaHistoryRow(?array $stage, float $mined, float $miningCost): array
    {
        $stage ??= [];
        $stage['period'] = '2026-10';
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
