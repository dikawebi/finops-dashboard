import '../css/production-map.css';
// Geographic presentation only: quantities and costs are supplied by the overview.
export function mountProductionMap(panel, options) {
    const { card, buaStock, stockLine } = options;
    const map = panel.querySelector('.overview-flow-map');
    map.classList.add('production-geography');
    map.querySelectorAll('.overview-bypass, .overview-vessel-direct, .overview-scene').forEach(node => node.remove());
    const locations = map.querySelector('.overview-flow-cards');
    const [buaQuantity, buaRate = ''] = stockLine(buaStock).split(' · ');
    locations.insertAdjacentHTML('beforeend', card('Stockpile BUA', buaRate, 'Closing inventory', [['Closing stock', stockLine(buaStock)]], buaQuantity));
    const metricLabels = { 'Coal Mining': 'Coal getting', 'Pit Takalot': 'Coal getting', 'Pit North G': 'Coal getting', 'Stockpile BUA': 'Closing stock', 'Washing Plant': 'Clean coal', 'BP Port': 'Closing stock', 'ISP': 'Closing stock', 'Mother Vessel': 'Sales volume' };
    locations.querySelectorAll('[data-overview-card]').forEach(item => {
        item.querySelector('.overview-scene')?.remove();
        const label = document.createElement('span');
        label.className = 'production-metric-label';
        label.textContent = metricLabels[item.dataset.overviewCard];
        const values = item.querySelector('.overview-card-values');
        values.prepend(label);
        const comparison = options.periodComparison;
        const current = comparison?.currentRates?.[item.dataset.overviewCard];
        const previous = comparison?.previousRates?.[item.dataset.overviewCard];
        if (document.getElementById('overview-date-mode')?.value !== 'daily' && Number.isFinite(current) && Number.isFinite(previous)) {
            const change = current - previous;
            const delta = document.createElement('span');
            const percent = previous ? ` (${(Math.abs(change / previous) * 100).toFixed(1)}%)` : '';
            delta.className = `production-period-delta ${change > 0 ? 'is-positive' : change < 0 ? 'is-negative' : 'is-neutral'}`;
            delta.textContent = Math.abs(change) < 0.005
                ? `— Unchanged vs ${comparison.label}`
                : `${change > 0 ? '▲' : '▼'} USD ${options.flowRate(Math.abs(change))}${percent} vs ${comparison.label}`;
            delta.title = `Cost per MT: USD ${options.flowRate(current)}; previous period: USD ${options.flowRate(previous)} / MT`;
            values.append(delta);
        }
    });
    panel.querySelector('.overview-flow-detail').setAttribute('aria-live', 'polite');
    map.insertAdjacentHTML('afterbegin', '<img class="production-landscape" src="/images/production-flow-map-v8.png" alt="North G pit connects by gravel road to BUA and Washing Plant. A continuous gravel haul road follows the inland route to BP Port. BP Port and ISP connect by barge along the central river; a separate ship route continues to the offshore Mother Vessel. BP Port uses a belt loading conveyor." width="1672" height="941"><div class="production-map-heading">Production flow <small>Illustrative geography · select a location for details</small></div>');
    const routes = document.createElement('div');
    routes.className = 'production-route-strip';
    map.querySelectorAll('.overview-route').forEach(route => routes.append(route));
    const portRoutes = document.createElement('section');
    portRoutes.className = 'production-port-routes';
    portRoutes.setAttribute('aria-label', 'Hauling to Port');
    portRoutes.innerHTML = '<strong class="production-port-heading">Hauling to Port</strong>';
    ['buaPort', 'wpPort'].forEach(key => portRoutes.append(routes.querySelector(`[data-overview-route="${key}"]`)));
    routes.append(portRoutes);
    map.append(routes);
    map.insertAdjacentHTML('beforeend', '<svg class="production-shipping" viewBox="0 0 1672 941" aria-label="Separate river routes: barge from BP Port to ISP and ship from BP Port to Mother Vessel"><defs><marker id="production-ship-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0L10 5L0 10z" fill="#75e7ff"/></marker><marker id="production-barge-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0L10 5L0 10z" fill="#ffd166"/></marker></defs><path d="M1005 675 L1530 370" fill="none" stroke="#75e7ff" stroke-width="4" stroke-dasharray="12 9" marker-end="url(#production-ship-arrow)"/><text x="1440" y="430" fill="#fff" stroke="#153442" stroke-width="4" paint-order="stroke" font-size="18" text-anchor="middle">BP Port → Mother Vessel</text><path d="M970 628 L1365 445" fill="none" stroke="#ffd166" stroke-width="4" stroke-dasharray="9 8" marker-end="url(#production-barge-arrow)"/><text x="1170" y="555" fill="#fff" stroke="#153442" stroke-width="4" paint-order="stroke" font-size="18" text-anchor="middle">BP Port → ISP · Barge</text></svg>');
    attachMapPopover(panel, map, options);
}

function attachMapPopover(panel, map, options) {
    const popup = panel.querySelector('.overview-flow-detail');
    popup.hidden = false;
    popup.setAttribute('popover', 'auto');
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-label', 'Production details');
    popup.id = 'production-location-detail';
    popup.classList.add('production-detail-popover');
    let active = null, pinned = false, timer, listeners, revision = 0;
    let costsPromise;
    let matrixPromise;
    const matchingDetails = async item => {
        const { stages, qty, number, flowRate, period, mode, dailyData, dbMetrics, api } = options;
        const detailQty = options.detailQty || qty;
        const stock = id => stages.find(stage => stage.id === id);
        const mt = value => value == null ? '—' : `${detailQty(value)} MT`;
        const ledgerRows = stageIds => stageIds.flatMap(id => {
            const stage = stock(id);
            return (stage?.rows || []).map(row => [
                stage.name || id, row.label,
                row.qtyMT == null ? '—' : mt(Number(row.qtyMT)),
                row.valueUSD == null ? '—' : `USD ${number(Number(row.valueUSD))}`,
                row.rateUSD == null ? '—' : `USD ${flowRate(Number(row.rateUSD))} / MT`,
                row.source === 'manual_input' ? 'Manual input' : row.source || (row.type === 'opening' ? 'Opening balance' : '—'),
            ]);
        });
        const locationLedger = async (title, stageIds, cardName, note) => {
            costsPromise ||= api(`/api/db-cost-summary?period=${period}&mode=${mode}`).catch(error => { costsPromise = null; throw error; });
            matrixPromise ||= api(`/api/cost-matrix?period=${period}&mode=${mode}&report=internal`).catch(error => { matrixPromise = null; throw error; });
            const [costSummary, matrix] = await Promise.all([costsPromise, matrixPromise]);
            const months = mode === 'ytd' ? Array.from({length:Number(period.slice(5))}, (_, index) => `${period.slice(0,4)}-${String(index+1).padStart(2,'0')}`) : [period];
            const activity = name => (costSummary.rows || []).find(row => row.entity === 'BP' && row.activity === name);
            const sourceAmount = row => row ? Number(row.sourceAmount ?? (mode === 'ytd' ? months.reduce((sum, month) => sum + Number(row.sourceMonths?.[month] || 0), 0) : row.sourceMonths?.[period] ?? 0)) : null;
            const glAmount = row => row ? Number(row.glAmount ?? (mode === 'ytd' ? months.reduce((sum, month) => sum + Number(row.glMonths?.[month] || 0), 0) : row.glMonths?.[period] ?? 0)) : null;
            const allocationsByPeriod = new Map((costSummary.overheadAllocations || []).map(row => [row.period, row]));
            const allocationAmount = field => {
                const values = months.map(month => allocationsByPeriod.get(month)?.[field]);
                return values.length && values.every(value => value !== null && value !== undefined && Number.isFinite(Number(value)))
                    ? values.reduce((sum, value) => sum + Number(value), 0) : null;
            };
            const costRows = [];
            const addCost = (label, amount, source) => { if (amount !== null && Number.isFinite(amount)) costRows.push([cardName, label, '—', `USD ${number(amount)}`, '—', source]); };
            const addAllocation = (location, field) => {
                const amount = allocationAmount(field);
                if (amount === null) costRows.push([cardName, `Allocated JKT & PC / ISP Rent · ${location}`, '—', '—', '—', 'No synced allocation for selected period']);
                else addCost(`Allocated JKT & PC / ISP Rent · ${location}`, amount, 'Google Sheets · WA COGP-COGS');
            };
            const grand = matrix.grandTotal || {};
            const overhead = activity('Overhead BUA'), pool = activity('OVH JKT & PC'), rent = activity('ISP Rent');
            if (cardName === 'Coal Mining') {
                addCost('Mining COGP', Number(grand.costMining || 0), 'D365 GL · Costing to Mining');
                addCost('Direct Overhead BUA · synchronized sheet source', sourceAmount(overhead), 'Google Sheets · DB Cost Summary');
                addCost('Overhead BUA · D365 GL mapped value', glAmount(overhead), 'D365 GL · Valuation to Mining');
                addCost('JKT & PC overhead pool · not allocated by location', sourceAmount(pool), 'Google Sheets · DB Cost Summary');
                addCost('ISP Rent pool · not allocated by location', sourceAmount(rent), 'Google Sheets · DB Cost Summary');
                addAllocation('BUA', 'allocatedBuaUSD');
            } else if (cardName === 'Stockpile BUA') {
                addCost('Mining COGP added to BUA stock', Number(grand.costMining || 0), 'D365 GL · Costing to Mining');
                addCost('Direct Overhead BUA · synchronized sheet source', sourceAmount(overhead), 'Google Sheets · DB Cost Summary · shown separately from stock valuation');
                addAllocation('BUA', 'allocatedBuaUSD');
            } else if (cardName === 'Washing Plant') {
                addCost('Coal Hauling BUA to WP', Number(grand.costHaulingBUAToWP || 0), 'D365 GL · route allocation');
                addCost('Washing Plant COGP', Number(grand.costWP || 0), 'D365 GL · Costing to Washing Plant');
                addCost('Washplant · synchronized sheet source', sourceAmount(activity('Washplant')), 'Google Sheets · DB Cost Summary');
                addCost('Washplant · D365 GL valuation addition', glAmount(activity('Washplant')), 'D365 GL · Valuation to Washing Plant');
                addAllocation('Washing Plant', 'allocatedWashUSD');
            } else if (cardName === 'BP Port') {
                addCost('Coal Hauling BUA to Port', Number(grand.costHaulingBUAToPort || 0), 'D365 GL · route allocation');
                addCost('Coal Hauling WP to Port', Number(grand.costHaulingWPToPort || 0), 'D365 GL · route allocation');
                addCost('BP PORT COGP', Number(grand.costPort || 0), 'D365 GL · Costing to BP PORT');
                addCost('Loading BLC · synchronized sheet source', sourceAmount(activity('Loading BLC')), 'Google Sheets · DB Cost Summary');
                addCost('Loading BLC · D365 GL valuation addition', glAmount(activity('Loading BLC')), 'D365 GL · Valuation to BP PORT');
                addAllocation('BP Port', 'allocatedPortUSD');
            } else if (cardName === 'ISP') {
                addCost('ISP Rent · synchronized sheet source', sourceAmount(rent), 'Google Sheets · DB Cost Summary');
                addCost('Transhipment BP Port to ISP', sourceAmount(activity('Transhipmet BP Port To ISP')), 'Google Sheets · DB Cost Summary');
                addCost('Transhipment ISP to Taboneo', sourceAmount(activity('Transhipmet ISP to Taboneo')), 'Google Sheets · DB Cost Summary');
                addAllocation('ISP', 'allocatedIspUSD');
            }
            return {
                title, columns:['Stage','Movement / cost detail','Quantity','Amount','Rate','Source'],
                tableRows:[...costRows, ...ledgerRows(stageIds)],
                note:`${note} Cost allocation is shown from the 8000 D365 GL and synchronized Google Sheets. The JKT & PC / ISP Rent pool is shown as source data; 8000 has no synced per-location allocation, so the pool is not presented as an allocated location amount. Inventory roll-forward rows remain the basis for stock quantities and moving-average values.`,
            };
        };
        if (item.dataset.overviewCard === 'Coal Mining') {
            if (document.getElementById('overview-date-mode')?.value === 'daily') return { title:'Coal Mining — Activity details', rows:[], note:'Daily activity cost breakdown is unavailable; select MTD or YTD to view synchronized DB costs.' };
            const labels = ['OB Removal include blasting','OB removal freedig','OB Overdistance','Topsoil','Mud','Coal Getting + Hauling 1km','Coal Hauling Overdistance'];
            const metricKeys = { 'OB Removal include blasting':'blasting','OB removal freedig':'freedig','Topsoil':'topsoil','Mud':'mud','Coal Getting + Hauling 1km':'coalGetting','Coal Hauling Overdistance':'coalGetting' };
            const sourceRows = dailyData.miningBreakdown || [];
            const periods = mode === 'ytd' ? Array.from({length:Number(period.slice(5))}, (_, index) => `${period.slice(0,4)}-${String(index+1).padStart(2,'0')}`) : [period];
            const internalDetail = options.reportType === 'internal';
            const detailPeriods = internalDetail ? [period] : periods;
            let metrics = dbMetrics || {};
            if (internalDetail && mode === 'ytd') {
                const monthlyProduction = await api(`/api/production-summary?period=${period}&mode=mtd`);
                metrics = monthlyProduction.dbMetrics || {};
            }
            const metric = key => metrics[key]?.value == null ? null : Number(metrics[key].value);
            const tableRows = labels.map(label => {
                const qtyValue = label === 'OB Overdistance'
                    ? [metric('blasting'), metric('freedig')].every(value => value != null) ? metric('blasting') + metric('freedig') : null
                    : metricKeys[label] ? metric(metricKeys[label]) : null;
                const sourceAmount = (section) => {
                    const matching = sourceRows.filter(row => row.label.toLowerCase() === label.toLowerCase() && row.section === section && row.entity === 'HRS');
                    const values = detailPeriods.map(month => matching.find(row => row.period === month)?.amount_usd);
                    return matching.length && values.every(value => value != null && Number.isFinite(Number(value))) ? values.reduce((sum, value) => sum + Number(value), 0) : null;
                };
                const operation = sourceAmount('operation');
                const fuel = sourceAmount('fuel');
                const total = operation == null || fuel == null ? null : operation + fuel;
                return [label, qtyValue == null ? '—' : `${detailQty(qtyValue)} ${['OB Removal include blasting','OB removal freedig','OB Overdistance','Topsoil','Mud'].includes(label) ? 'Bcm' : 'MT'}`, operation == null ? '—' : `USD ${number(operation)}`, fuel == null ? '—' : `USD ${number(fuel)}`, total == null ? '—' : `USD ${number(total)}`, total == null || !metric('coalGetting') ? '—' : `USD ${flowRate(total / metric('coalGetting'))} / MT`];
            });
            if (options.reportType === 'internal') {
                costsPromise ||= api(`/api/db-cost-summary?period=${period}&mode=${mode}`).catch(error => { costsPromise = null; throw error; });
                const summary = await costsPromise;
                const overheadRow = (summary.rows || []).find(row => row.entity === 'BP' && row.activity === 'Overhead BUA');
                const directOverheadValue = overheadRow?.sourceMonths?.[period];
                const directOverhead = directOverheadValue !== null && directOverheadValue !== undefined && Number.isFinite(Number(directOverheadValue))
                    ? Number(directOverheadValue) : null;
                const allocationRows = new Map((summary.overheadAllocations || []).map(row => [row.period, row]));
                const allocationValue = allocationRows.get(period)?.allocatedBuaUSD;
                const allocation = allocationValue !== null && allocationValue !== undefined && Number.isFinite(Number(allocationValue))
                    ? Number(allocationValue) : null;
                const coalGetting = metric('coalGetting');
                const costRate = amount => amount !== null && amount !== undefined && Number.isFinite(Number(amount)) && coalGetting > 0
                    ? `USD ${flowRate(Number(amount) / coalGetting)} / MT coal`
                    : '—';
                tableRows.push(['Direct Overhead BUA', '—', '—', directOverhead === null ? '—' : `USD ${number(directOverhead)}`, directOverhead === null ? '—' : `USD ${number(directOverhead)}`, costRate(directOverhead)]);
                tableRows.push([
                    'Allocated JKT & PC / ISP Rent', '—', '—', '—', allocation === null ? '—' : `USD ${number(allocation)}`,
                    costRate(allocation),
                ]);
            }
            const basisNote = options.reportType === 'internal'
                ? 'Seven DB activity rows, Direct Overhead BUA, and allocated JKT & PC / ISP Rent follow localhost:3000 Internal detail.'
                : 'Seven DB activity rows follow localhost:3000 External detail.';
            return { title:'Coal Mining — Activity details', columns:['Activity','Quantity','Operation cost','Fuel cost','Total cost','Cost / MT · Source'], tableRows, note:`${basisNote} Quantities and activity costs use synchronized Google Sheets DB data for ${mode.toUpperCase()} ${period}. Overhead allocation is shown only in Internal mode; it is not added to inventory valuation.` };
        }
        if (item.dataset.overviewCard === 'Stockpile BUA') return locationLedger('Stockpile BUA — Movement & cost detail', ['bua','transitBuaWp','transitBuaPort'], 'Stockpile BUA', `Opening, receipts, costs, available balance, route loading, adjustments and closing are from the 8000 inventory roll-forward for ${period}. Route transit is shown as its own stage.`);
        if (['Washing Plant', 'BP Port', 'ISP'].includes(item.dataset.overviewCard)) {
            if (options.reportType !== 'internal') return null;
            const location = item.dataset.overviewCard;
            const breakdown = options.internalBreakdown?.(location);
            if (!breakdown) return {
                title: `${location} — WA Cost Breakdown`,
                sections: [{ title: '', rows: [['WA COGP-COGS breakdown is unavailable', options.periodLabel || period]] }],
            };
            const rows = breakdown.items.map(entry => [
                entry.label,
                `${entry.qty === null ? '—' : qty(entry.qty)} · USD ${number(entry.amount)} · ${breakdown.qty ? `USD ${flowRate(entry.amount / breakdown.qty)} / MT available` : '—'}`,
            ]);
            if (Math.abs(breakdown.difference) > 0.01) rows.push(['Unreconciled difference', `USD ${number(breakdown.difference)}`]);
            rows.push(['Total available / WA COGP', `${qty(breakdown.qty)} · USD ${number(breakdown.total)} · ${breakdown.rate === null ? '—' : `USD ${flowRate(breakdown.rate)} / MT`}`]);
            const sections = [{ title: '', rows }];
            if (location === 'Washing Plant') {
                const data = options.inventory?.internalWPOperational || {};
                const months = Object.entries(data).filter(([month]) => month >= (options.inventory?.rangeStart || period) && month <= (options.inventory?.periodKey || period));
                const value = key => months.length && months.every(([, entry]) => Number.isFinite(entry[key]))
                    ? qty(months.reduce((sum, [, entry]) => sum + entry[key], 0)) : '—';
                sections.push({ title: 'Washing Plant — Operational Movements', rows: [
                    ['Raw coal received from BUA', value('fromBuaMT')],
                    ['Feed to washing', value('feedMT')],
                    ['Clean coal produced', value('cleanCoalMT')],
                    ['Reject / High Ash', value('rejectMT')],
                    ['Transfer to Port', value('toPortMT')],
                    ['Combined WP closing', `${qty(breakdown.qty)} · USD ${flowRate(breakdown.rate)} / MT`],
                ], note:'WA COGP-COGS supplies combined WP stock and transfers. Feed and reject are operating volumes; separate WP stock balances are unavailable.' });
            }
            return {
                title: `${location} — WA Cost Breakdown`,
                sections,
                note: `WA COGP-COGS, ${options.periodLabel || period}. Component USD/MT uses the available stock quantity; components reconcile to the weighted-average COGP. YTD view shows the selected month’s inventory position.`,
            };
        }
        const route = { buaWp: ['5000407','Coal Hauling ROM BUA to WP'], wpPort: ['5000408','Coal Hauling WP to Port'], buaPort: ['5000409','Coal Hauling ROM BUA to Port'] }[item.dataset.overviewRoute];
        if (!route) return null;
        const title = `${item.querySelector('summary span').textContent} — Cost by Entity`;
        if (document.getElementById('overview-date-mode')?.value === 'daily') return {title, rows: [], note: 'Daily entity costs are unavailable; DB provides monthly costs.'};
        costsPromise ||= api(`/api/db-cost-summary?period=${period}&mode=${mode}`).catch(error => { costsPromise = null; throw error; });
        const costs = await costsPromise;
        const months = mode === 'ytd' ? Array.from({length:Number(period.slice(5))}, (_, index) => `${period.slice(0,4)}-${String(index+1).padStart(2,'0')}`) : [period];
        const rows = ['HRS','BP','SMA'].map(entity => {
            const volumes = (dailyData.haulingEntities || []).filter(row => row.account_code === route[0] && row.entity.toUpperCase() === entity);
            const complete = months.every(month => volumes.some(row => row.period === month && row.qty != null));
            const volume = complete ? volumes.reduce((sum,row) => sum + Number(row.qty || 0),0) : null;
            const matching = (costs.rows || []).filter(row => row.activity === route[1] && row.entity === entity);
            const values = matching.flatMap(row => months.map(month => row.months?.[month]));
            const cost = matching.length && values.every(value => value != null && Number.isFinite(Number(value))) ? values.reduce((sum,value) => sum + Number(value),0) : null;
            return [entity, !volume ? '—' : `${mt(volume)} · ${cost == null ? 'USD — / MT' : `USD ${flowRate(cost / volume)} / MT`}`];
        });
        return {title, rows, note: `DB coal hauling volume · ${mode.toUpperCase()} · USD/MT = entity cost ÷ entity volume. Missing synchronized DB values are shown as —.`};
    };
    const close = () => { clearTimeout(timer); if (popup.matches(':popover-open')) popup.hidePopover(); };
    const position = () => {
        if (!active?.isConnected) { close(); return; }
        const anchor = active.querySelector('summary').getBoundingClientRect();
        const box = popup.getBoundingClientRect();
        const left = Math.max(12, Math.min(anchor.left + anchor.width / 2 - box.width / 2, innerWidth - box.width - 12));
        const below = anchor.bottom + 10;
        const top = below + box.height <= innerHeight - 12 ? below : Math.max(12, anchor.top - box.height - 10);
        popup.style.left = `${left}px`;
        popup.style.top = `${top}px`;
    };
    const show = (item, pin = false) => {
        clearTimeout(timer);
        if (pinned && !pin) return;
        if (active && active !== item) { active.open = false; active.querySelector('summary').setAttribute('aria-expanded', 'false'); }
        active = item; pinned = pin; item.open = true;
        const summary = item.querySelector('summary');
        summary.setAttribute('aria-expanded', 'true');
        popup.replaceChildren();
        const heading = document.createElement('strong');
        heading.textContent = `${summary.querySelector('span')?.textContent || 'Production'} — Details`;
        const dismiss = document.createElement('button');
        dismiss.type = 'button'; dismiss.className = 'production-detail-close'; dismiss.textContent = '×';
        dismiss.setAttribute('aria-label', 'Close production details');
        dismiss.addEventListener('click', () => { close(); summary.focus(); });
        const content = document.createElement('div');
        const source = item.querySelector(':scope > div, :scope > p');
        if (source) content.append(...Array.from(source.childNodes, node => node.cloneNode(true)));
        const loading = document.createElement('div');
        loading.className = 'production-detail-loading';
        loading.setAttribute('role', 'status');
        loading.innerHTML = '<span>Loading details…</span><span class="production-detail-progress" role="progressbar" aria-label="Loading details"><i></i></span>';
        content.append(loading);
        popup.append(heading, dismiss, content);
        const currentRevision = ++revision;
        matchingDetails(item).then(result => {
            if (active !== item || revision !== currentRevision) return;
            if (!result) { loading.remove(); position(); return; }
            heading.textContent = result.title;
            content.replaceChildren();
            if (result.sections) {
                result.sections.forEach(section => {
                    if (section.title) {
                        const label = document.createElement('strong');
                        label.className = 'production-detail-section-title';
                        label.textContent = section.title;
                        content.append(label);
                    }
                    section.rows.forEach(([name, value]) => {
                        const line = document.createElement('div'); line.className = 'overview-detail-line';
                        const label = document.createElement('span'); label.textContent = name;
                        const amount = document.createElement('strong'); amount.textContent = value;
                        line.append(label, amount); content.append(line);
                    });
                    if (section.note) { const note = document.createElement('small'); note.textContent = section.note; content.append(note); }
                });
            } else if (result.columns && result.tableRows) {
                const wrapper = document.createElement('div'); wrapper.className = 'production-detail-table-wrap';
                const table = document.createElement('table'); table.className = 'production-detail-table';
                const thead = document.createElement('thead'), headerRow = document.createElement('tr');
                result.columns.forEach(value => { const th = document.createElement('th'); th.textContent = value; headerRow.append(th); });
                thead.append(headerRow); table.append(thead);
                const tbody = document.createElement('tbody');
                result.tableRows.forEach(values => { const row = document.createElement('tr'); values.forEach(value => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); }); tbody.append(row); });
                table.append(tbody); wrapper.append(table); content.append(wrapper);
            } else result.rows.forEach(([name, value]) => {
                const line = document.createElement('div'); line.className = 'overview-detail-line';
                const label = document.createElement('span'); label.textContent = name;
                const amount = document.createElement('strong'); amount.textContent = value;
                line.append(label, amount); content.append(line);
            });
            const note = document.createElement('small'); note.textContent = result.note; content.append(note); position();
        }).catch(() => { if (active === item && revision === currentRevision) content.textContent = 'DB data unavailable. Please sync production data.'; });
        if (!popup.matches(':popover-open')) popup.showPopover();
        position();
        listeners?.abort(); listeners = new AbortController();
        window.addEventListener('resize', position, { signal: listeners.signal });
        window.addEventListener('scroll', position, { capture: true, signal: listeners.signal });
    };
    const deferClose = () => { if (!pinned) timer = setTimeout(close, 180); };
    popup.addEventListener('pointerenter', () => clearTimeout(timer));
    popup.addEventListener('pointerleave', deferClose);
    popup.addEventListener('toggle', event => {
        if (event.newState === 'closed') {
            listeners?.abort(); clearTimeout(timer);
            if (active) { active.open = false; active.querySelector('summary').setAttribute('aria-expanded', 'false'); }
            active = null; pinned = false;
        }
    });
    map.querySelectorAll('details').forEach(item => {
        const summary = item.querySelector('summary');
        summary.setAttribute('aria-controls', popup.id);
        summary.setAttribute('aria-expanded', 'false');
        summary.addEventListener('click', event => {
            event.preventDefault();
            if (active === item && pinned) close(); else show(item, true);
        });
        summary.addEventListener('pointerenter', event => { if (event.pointerType === 'mouse') show(item); });
        summary.addEventListener('pointerleave', deferClose);
    });
}
