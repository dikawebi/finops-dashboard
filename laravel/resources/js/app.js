import './bootstrap';

const $ = selector => document.querySelector(selector);
const money = value => { const amount = Number(value || 0); const formatted = Math.abs(amount).toLocaleString('en-US', { maximumFractionDigits: 0 }); return `USD ${amount < 0 ? `(${formatted})` : formatted}`; };
const reportAmount = value => { const amount = Number(value || 0); if (Math.abs(amount) < 0.000001) return '-'; const formatted = Math.abs(amount).toLocaleString('en-US', { maximumFractionDigits: 0 }); return amount < 0 ? `(${formatted})` : formatted; };
const detailMoney = value => { const amount = Number(value); if (!Number.isFinite(amount)) return '—'; const formatted = Math.abs(amount).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); return amount < 0 ? `(${formatted})` : formatted; };
const qty = value => Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
const esc = value => String(value ?? '—').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[char]);
const companyName = document.body.dataset.companyName || 'FinOps Dashboard';
const state = { view: new URLSearchParams(location.search).get('view') || 'overview', costSearch: '', costParentOnly: false, costHideZero: false, costCollapsed: {}, varianceSearch: '', varianceHideZero: true, varianceCategory: 'all', varianceLocation: 'allCost' };
const api = async path => {
    const response = await fetch(path);
    if (!response.ok) throw new Error(`Request failed: ${response.status}`);
    return (await response.json()).data;
};
const badge = text => `<span class="source-badge">📂 ${esc(text)}</span>`;
const table = (headers, rows, extra = '') => `<div class="table-responsive"><table class="table-accounting ${extra}"><thead><tr>${headers.map(header => `<th>${esc(header)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.join('') : `<tr><td colspan="${headers.length}">No data available.</td></tr>`}</tbody></table></div>`;
const card = (title, content, classes = '') => `<section class="table-card ${classes}"><div class="card-title">${title}</div>${content}</section>`;
const sectionHeader = (title, description, actions = '') => `<div class="section-header"><div class="section-title"><h2>${title}</h2><p>${description}</p></div>${actions ? `<div class="section-actions">${actions}</div>` : ''}</div>`;
const unavailable = label => `<button class="btn btn-outline" type="button" disabled title="Deferred by project scope">${label}</button>`;
const downloadCsv = (filename, headers, rows) => {
    const quote = value => `"${String(value ?? '').replace(/"/g, '""')}"`;
    const content = [headers, ...rows].map(row => row.map(quote).join(',')).join('\r\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([content], { type: 'text/csv;charset=utf-8' }));
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
};
const downloadExcel = (filename, headers, rows) => {
    const cell = value => `<td>${esc(value)}</td>`;
    const html = `<html><head><meta charset="utf-8"></head><body><table><thead><tr>${headers.map(cell).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${row.map(cell).join('')}</tr>`).join('')}</tbody></table></body></html>`;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' }));
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
};

function updateNavigation(period, mode) {
    $('#periodText').textContent = period;
    $('#viewText').textContent = `${mode.toUpperCase()} — ${period}`;
    document.querySelectorAll('.tabs a').forEach(tab => tab.classList.toggle('active', tab.dataset.view === state.view));
    history.replaceState(null, '', `/?view=${state.view}&period=${period}&mode=${mode}`);
}

async function overview(period, mode) {
    const [cost, financial, inventory, production] = await Promise.all([
        api(`/api/cost-matrix?period=${period}&mode=${mode}`),
        api(`/api/financial-statements?period=${period}&mode=${mode}`),
        api(`/api/inventory-valuation?period=${period}&mode=${mode}`),
        api(`/api/production-summary?period=${period}&mode=${mode}`),
    ]);
    const metrics = Array.isArray(production.dbMetrics) ? production.dbMetrics : Object.values(production.dbMetrics || {});
    const daily = production.dailyTrends || [];
    const productionMT = Number(production.dbMetrics?.coalGetting?.value || metrics.find(item => item.metric_key === 'coalGetting')?.value || 0);
    const line = id => Number(financial.incomeStatement?.[id] ?? financial.sections?.profitLoss?.find(item => item.id === id)?.value ?? 0);
    const stages = inventory.stages || [];
    const inventoryValue = stages.reduce((total, stage) => total + Number(stage.endingValueUSD || 0), 0);
    const kpis = [
        ['TOTAL ALL COST (GL)', money(cost.grandTotal.allCost), 'Costing to allocation, including Direct Expense (USD)', 'blue'],
        ['TOTAL PRODUCTION VOLUME', `${qty(productionMT)} MT`, 'Coal mined / weighbridge volume', 'val'],
        ['AVERAGE COST / MT', productionMT ? `${money(Number(cost.grandTotal.allCost) / productionMT)} / MT` : '—', 'Total operating cost / net MT', 'cost'],
        ['TOTAL INVENTORY VALUE', money(inventoryValue), 'Provisional value — see quantity and cost sources', 'success'],
        ['GROSS PROFIT', Number(financial.incomeStatement?.grossProfit || 0) === 0 ? 'USD -' : money(line('grossProfit')), 'Coal sales less cost of sales', 'blue'],
        ['OPERATING PROFIT / BEFORE INTEREST', money(line('profitBeforeInterest')), 'Operating profit for the period', 'blue'],
    ];
    const topDrivers = cost.rows.filter(row => !row.isParent).sort((a, b) => Number(b.allCost) - Number(a.allCost)).slice(0, 8).map(row => { const percentage = Number(cost.grandTotal.allCost) ? Number(row.allCost) / Number(cost.grandTotal.allCost) * 100 : 0; return `<div class="driver-row"><div><span>${esc(row.name)}</span><i style="width:${Math.max(1, percentage)}%"></i></div><b>${money(row.allCost)} (${percentage.toFixed(0)}%)</b></div>`; }).join('');
    const maxMT = Math.max(...daily.map(item => Number(item.nettoMT) || 0), 10);
    const chartWidth = 560, chartHeight = 200, padding = 30;
    const barWidth = Math.max(5, Math.floor((chartWidth - padding * 2) / Math.max(daily.length, 1)) - 4);
    const bars = daily.map((item, index) => {
        const x = padding + index * (barWidth + 4);
        const height = Math.round((Number(item.nettoMT || 0) / maxMT) * (chartHeight - padding * 2));
        const day = item.date.slice(-2);
        return `<g><rect x="${x}" y="${chartHeight - padding - height}" width="${barWidth}" height="${height}" fill="#0891b2" rx="3"><title>${esc(item.date)}: ${qty(item.nettoMT)} MT</title></rect>${index % 2 === 0 ? `<text x="${x + barWidth / 2}" y="${chartHeight - 10}" text-anchor="middle" font-size="10" fill="#64748b">${day}</text>` : ''}</g>`;
    }).join('');
    const dailyChart = daily.length ? `<svg width="100%" height="${chartHeight}" viewBox="0 0 ${chartWidth} ${chartHeight}" role="img" aria-label="Daily production volume"><line x1="${padding}" y1="${chartHeight - padding}" x2="${chartWidth - padding}" y2="${chartHeight - padding}" stroke="#cbd5e1"/><line x1="${padding}" y1="${padding}" x2="${chartWidth - padding}" y2="${padding}" stroke="#e2e8f0" stroke-dasharray="4"/><text x="${padding - 5}" y="${padding + 10}" text-anchor="end" font-size="10" fill="#94a3b8">${qty(maxMT)} MT</text><text x="${padding - 5}" y="${chartHeight - padding}" text-anchor="end" font-size="10" fill="#94a3b8">0</text>${bars}</svg>` : '<p class="chart-empty">No daily production records for this period.</p>';
    $('#pageContent').innerHTML = `
        ${sectionHeader('Operations & Financial Overview', 'Microsoft Dynamics 365 General Ledger and Google Sheets weighbridge data')}
        <div class="kpi-grid overview-kpi-grid">${kpis.map(([label, value, sub, type]) => `<article class="kpi-card ${type}"><span class="kpi-title">${label}</span><strong class="kpi-value">${value}</strong><small class="kpi-sub">${sub}</small></article>`).join('')}</div>
        ${card(`Production by Activity — Google Sheets DB ${badge('production_daily_sheets.json')}`, `<p class="data-warning">Monthly figures use the DB sheet. Blank source cells are unavailable; YTD sums available months only. The daily chart below uses the Produksi sheet.</p>${table(['Activity', 'Volume', 'Unit', 'Availability'], metrics.map(item => `<tr><td>${esc(item.label || item.metric_key)}</td><td class="num">${item.value == null ? '—' : qty(item.value)}</td><td>${esc(item.unit)}</td><td>${item.value == null ? `No data: ${(item.missingPeriods || []).join(', ')}` : 'Available'}</td></tr>`))}`)}
        <div class="grid-2 overview-lower"><section class="card"><div class="card-title"><span>Cost Allocation: COGP &amp; Direct Expense</span>${badge('gl_data.json')}<small>USD</small></div><div class="allocation-grid"><article class="allocation-val"><b>DIRECT EXPENSE, GA &amp; SELLING</b><strong>${money(cost.grandTotal.totalExpense)}</strong><span>Direct Expense by Valuation to; Not Valuated is included in GA. Selling Expense has its own column.</span></article><article class="allocation-cost"><b>COGP</b><strong>${money(cost.grandTotal.totalCOGP)}</strong><span>From Costing to: Mining, 3 Coal Hauling routes, Washing Plant, BP PORT</span></article></div><b class="drivers-title">Top Cost Drivers</b><div>${topDrivers}</div></section><section class="card"><div class="card-title"><span>Daily Production Volume (MT)</span>${badge('production_daily_sheets.json')}<small>Google Sheets Data</small></div><p class="chart-caption">Daily recorded coal volume (MT):</p><div id="chart-daily-production">${dailyChart}</div><div class="chart-footer"><span>Source: Google Sheets or uploaded data</span><span>Update: Manual sync</span></div></section></div>`;
}

async function cost(period, mode) {
    const data = await api(`/api/cost-matrix?period=${period}&mode=${mode}`);
    const fields = [['costMining', 'Mining'], ['costHaulingBUAToWP', 'Haul BUA→WP'], ['costHaulingBUAToPort', 'Haul BUA→Port'], ['costHaulingWPToPort', 'Haul WP→Port'], ['costWP', 'Washing Plant'], ['costPort', 'BP PORT'], ['valMining', 'Mining'], ['valHauling', 'Coal Hauling'], ['valWP', 'Washing Plant'], ['valPort', 'BP PORT'], ['directGA', 'GA'], ['sellingExpense', 'Selling']];
    const search = state.costSearch.trim().toLowerCase();
    const visibleRows = data.rows.filter(row => (!search || `${row.code} ${row.name}`.toLowerCase().includes(search)) && (!state.costParentOnly || row.isParent) && (!state.costCollapsed[row.parent]) && (!state.costHideZero || Number(row.allCost || 0) !== 0));
    const unallocatedRows = data.rows.filter(row => !row.isParent && Math.abs(Number(row.unallocatedHauling || 0)) > 0.000001);
    const unallocatedTotal = unallocatedRows.reduce((sum, row) => sum + Number(row.unallocatedHauling || 0), 0);
    const rows = visibleRows.map(row => `<tr class="${row.isParent ? 'parent-row' : ''}"><td>${esc(row.code)}</td><td class="account-name">${row.isParent ? `<button type="button" class="account-toggle" data-toggle-parent="${esc(row.code)}" aria-label="${state.costCollapsed[row.code] ? 'Expand' : 'Collapse'} ${esc(row.name)}">${state.costCollapsed[row.code] ? '+' : '−'}</button>` : ''}${esc(row.name)}</td><td class="center">-</td><td class="num">${reportAmount(row.allCost)}</td>${fields.map(([field]) => `<td class="num">${reportAmount(row[field])}</td>`).join('')}</tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Report Cost: COGP & Direct Expense', 'Accounts beginning 7–9 are allocated to GA. Accounts beginning 5–6 follow Costing to; Direct Expense is allocated by Valuation to, with Not Valuated in GA. Selling Expense has its own column. Amounts in USD.', `<label>Month <input type="month" value="${period}" disabled></label><input id="costSearch" class="input-search" placeholder="🔍 Search account code / name..." value="${esc(state.costSearch)}"><button id="costShowAll" class="btn btn-outline" type="button">Show All Accounts</button><button id="costParentOnly" class="btn btn-outline" type="button">${state.costParentOnly ? 'Show All Rows' : 'Parent Accounts Only (Summary)'}</button><button id="costHideZero" class="btn btn-outline" type="button">${state.costHideZero ? 'Show Zero Balances' : 'Hide Zero Balances'}</button><button id="costExport" class="btn btn-primary" type="button">📥 Export Excel/CSV</button>`)}
        <div class="cost-report-card"><p class="table-note">Showing ${visibleRows.length} of ${data.rows.length} accounts</p><div class="cost-notice">Source: uploaded GL for the selected period.</div>${unallocatedRows.length ? `<div class="cost-notice">Coal Hauling has no route for ${unallocatedRows.length} accounts (net USD ${Math.round(Math.abs(unallocatedTotal)).toLocaleString('en-US')}). The balance remains in ALL Cost. Upload GL with Costing to: Coal Hauling BUA to WP, Coal Hauling Bua to Port, or Coal Hauling WP to Port.</div>` : ''}<div class="table-responsive"><table class="table-accounting cost-report-table"><thead><tr><th rowspan="2">AccountCode</th><th rowspan="2">AccountName</th><th rowspan="2">Check</th><th rowspan="2" class="all-cost-header">ALL Cost</th><th colspan="6" class="group-header">COGP</th><th colspan="6" class="group-header direct-group">Direct Expense</th></tr><tr>${fields.map(([, label]) => `<th>${esc(label)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody><tfoot><tr><th colspan="3">Grand Total</th><th class="num">${reportAmount(data.grandTotal.allCost)}</th>${fields.map(([field]) => `<th class="num">${reportAmount(data.grandTotal[field])}</th>`).join('')}</tr></tfoot></table></div></div>`;
    $('#costSearch').addEventListener('input', event => { state.costSearch = event.target.value; cost(period, mode); });
    $('#costShowAll').addEventListener('click', () => { state.costSearch = ''; state.costParentOnly = false; state.costHideZero = false; state.costCollapsed = {}; cost(period, mode); });
    $('#costParentOnly').addEventListener('click', () => { state.costParentOnly = !state.costParentOnly; cost(period, mode); });
    $('#costHideZero').addEventListener('click', () => { state.costHideZero = !state.costHideZero; cost(period, mode); });
    document.querySelectorAll('[data-toggle-parent]').forEach(button => button.addEventListener('click', () => { const code = button.dataset.toggleParent; state.costCollapsed[code] = !state.costCollapsed[code]; cost(period, mode); }));
    $('#costExport').addEventListener('click', () => {
        const headers = ['Account Code', 'Account Name', 'All Cost', ...fields.map(([, label]) => label)];
        const exportRows = visibleRows.map(row => [row.code, row.name, row.allCost, ...fields.map(([field]) => row[field])]);
        downloadCsv(`report-cost-${period}-${mode}.csv`, headers, exportRows);
        downloadExcel(`report-cost-${period}-${mode}.xls`, headers, exportRows);
    });
}

async function variance(period, mode) {
    const data = await api(`/api/cost-variance?period=${period}&mode=${mode}&location=${encodeURIComponent(state.varianceLocation)}`);
    const search = state.varianceSearch.trim().toLowerCase();
    const categoryValue = row => row.categories?.current?.[state.varianceCategory] ?? row.current;
    const visibleRows = data.rows.filter(row => (!search || `${row.code} ${row.name}`.toLowerCase().includes(search)) && (!state.varianceHideZero || Number(row.previous || 0) !== 0 || Number(row.current || 0) !== 0) && (state.varianceCategory === 'all' || Number(categoryValue(row) || 0) !== 0));
    const rows = visibleRows.map(row => `<tr class="${row.isParent ? 'parent-row' : ''}"><td>${esc(row.code)}</td><td>${esc(row.name)}</td><td class="num">${reportAmount(row.previous)}</td><td class="num">${reportAmount(row.current)}</td><td class="num ${row.change > 0 ? 'negative' : 'positive'}">${reportAmount(row.change)}</td><td class="num">${row.changePct == null ? '—' : `${Number(row.changePct).toFixed(2)}%`}</td><td><input class="remark-input" value="${esc(row.remark || '')}" placeholder="No remark" disabled></td></tr>`);
    /*
    $('#pageContent').innerHTML = `
        ${sectionHeader('Cost Variance Analysis', 'Compare monthly cost by account and location; record explanations for each variance.', `<button id="varianceExcel" class="btn btn-outline" type="button">📊 Export Excel</button>`)}
        ${card(`<div class="report-source-row">${badge('gl_data.json')} ${badge('cost_variance_remarks.json')}</div><div class="variance-controls"><label>Current month <input type="month" value="${period}" disabled></label><label>Location / category <select id="varianceLocation"><option value="">ALL Cost</option>${(data.locations || []).map(item => `<option value="${esc(item)}" ${state.varianceLocation === item ? 'selected' : ''}>${esc(item)}</option>`).join('')}</select></label><label>Search account <input id="varianceSearch" type="search" placeholder="Account code or name" value="${esc(state.varianceSearch)}"></label><label class="variance-checkbox"><input id="varianceHideZero" type="checkbox" ${state.varianceHideZero ? 'checked' : ''}> Hide zero balances</label><label class="variance-category">Category <select id="varianceCategory"><option value="all" ${state.varianceCategory === 'all' ? 'selected' : ''}>All categories</option><option value="cogp" ${state.varianceCategory === 'cogp' ? 'selected' : ''}>COGP</option><option value="direct" ${state.varianceCategory === 'direct' ? 'selected' : ''}>Direct Expense</option></select></label></div><p class="variance-note">MTD: Oct 26 vs Sep 26. Sep 26: workbook; Oct 26: GL. Variance = current − previous; % uses the absolute previous balance. Zero or unavailable base is shown as —. Remarks are saved by month, category and account.</p><p class="table-note">${visibleRows.length} rows • Comparison ${data.previousPeriod} → ${data.period}</p>`, table(['Account', 'Account Name', 'Previous month', 'Current month', 'Variance USD', 'Variance %', 'Remark'], rows, 'variance-table'))}
    */
    const varianceOptions = [['allCost', 'ALL Cost'], ['costMining', 'COGP - Mining'], ['costHaulingBUAToWP', 'COGP - Coal Hauling BUA to WP'], ['costHaulingBUAToPort', 'COGP - Coal Hauling Bua to Port'], ['costHaulingWPToPort', 'COGP - Coal Hauling WP to Port'], ['costWP', 'COGP - Washing Plant'], ['costPort', 'COGP - BP Port'], ['valMining', 'Direct Expense - Mining'], ['valHauling', 'Direct Expense - Coal Hauling'], ['valWP', 'Direct Expense - Washing Plant'], ['valPort', 'Direct Expense - BP PORT'], ['directGA', 'Direct Expense - GA'], ['sellingExpense', 'Selling Expense']];
    const controls = `<div class="report-source-row">${badge('gl_data.json')} ${badge('cost_variance_remarks.json')}</div><div class="variance-controls"><label>Current month <input type="month" value="${period}" disabled></label><label>Location / category <select id="varianceLocation">${varianceOptions.map(([value, label]) => `<option value="${value}" ${state.varianceLocation === value ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label><label>Search account <input id="varianceSearch" type="search" placeholder="Account code or name" value="${esc(state.varianceSearch)}"></label><label class="variance-checkbox"><input id="varianceHideZero" type="checkbox" ${state.varianceHideZero ? 'checked' : ''}> Hide zero balances</label></div><p class="variance-note">MTD: Oct 26 vs Sep 26. Sep 26: workbook; Oct 26: GL. Variance = current − previous; % uses the absolute previous balance. Zero or unavailable base is shown as —. Remarks are saved by month, category and account.</p><p class="table-note">${visibleRows.length} rows • Comparison ${data.previousPeriod} → ${data.period}</p>`;
    const varianceTotals = { previous: Number(data.previous?.grandTotal?.[state.varianceLocation] ?? 0), current: Number(data.current?.grandTotal?.[state.varianceLocation] ?? 0) };
    varianceTotals.change = varianceTotals.current - varianceTotals.previous;
    const varianceTotalPct = varianceTotals.previous === 0 ? null : varianceTotals.change / Math.abs(varianceTotals.previous) * 100;
    const varianceTable = `<div class="table-responsive"><table class="table-accounting variance-table"><thead><tr>${['Account', 'Account Name', 'Previous month', 'Current month', 'Variance USD', 'Variance %', 'Remark'].map(header => `<th>${esc(header)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.join('') : `<tr><td colspan="7">No data available.</td></tr>`}</tbody><tfoot><tr><th colspan="2">Total</th><th class="num">${reportAmount(varianceTotals.previous)}</th><th class="num">${reportAmount(varianceTotals.current)}</th><th class="num ${varianceTotals.change > 0 ? 'negative' : 'positive'}">${reportAmount(varianceTotals.change)}</th><th class="num">${varianceTotalPct == null ? '—' : `${varianceTotalPct.toFixed(3)}%`}</th><th></th></tr></tfoot></table></div>`;
    $('#pageContent').innerHTML = `${sectionHeader('Cost Variance Analysis', 'Compare monthly cost by account and location; record explanations for each variance.', `<button id="varianceExcel" class="btn btn-outline" type="button">📊 Export Excel</button>`)}${card(controls, varianceTable)}`;
    $('#varianceSearch').addEventListener('input', event => { state.varianceSearch = event.target.value; variance(period, mode); });
    $('#varianceHideZero').addEventListener('change', event => { state.varianceHideZero = event.target.checked; variance(period, mode); });
    $('#varianceCategory')?.addEventListener('change', event => { state.varianceCategory = event.target.value; variance(period, mode); });
    $('#varianceLocation').addEventListener('change', event => { state.varianceLocation = event.target.value; variance(period, mode); });
    $('#varianceExcel').addEventListener('click', () => downloadExcel(`cost-variance-${period}-${mode}.xls`, ['Account', 'Account Name', 'Previous month', 'Current month', 'Variance USD', 'Variance %', 'Remark'], visibleRows.map(row => [row.code, row.name, row.previous, row.current, row.change, row.changePct == null ? '' : row.changePct, row.remark || ''])));
}

async function metric(period, mode) {
    const [data, dbSummary] = await Promise.all([api(`/api/cost-per-mt?period=${period}&mode=${mode}`), api(`/api/db-cost-summary?period=${period}&mode=${mode}`)]);
    const stageCards = data.stages.map(stage => `<article class="stage-card"><span>${esc(stage.stage)}</span><strong>${stage.costPerMT == null ? 'USD —' : `USD ${detailMoney(stage.costPerMT)}`}</strong><small>per stage MT (${qty(stage.metricTons)} MT)</small><em>Total Cost: ${money(stage.totalCost)} (USD)</em></article>`).join('');
    const rows = data.stages.map(stage => `<tr><td><strong>${esc(stage.stage)}</strong></td><td class="num">${reportAmount(stage.valuationCost)}</td><td class="num">${reportAmount(stage.operatingCost)}</td><td class="num">${reportAmount(stage.totalCost)}</td><td class="num">${qty(stage.metricTons)} MT</td><td class="num stage-rate">${stage.costPerMT == null ? 'USD —' : `USD ${detailMoney(stage.costPerMT)}`}</td><td class="num">${stage.costPerTotalMT == null ? 'USD —' : `USD ${detailMoney(stage.costPerTotalMT)}`}</td></tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Cost per Metric Ton Analysis', 'D365 accounting costs and Google Sheets weighbridge volume')}
        <p class="metric-notice">Stage rates use DB volumes: Coal Getting, the three hauling routes, Washing Plant Feed, and Port Loading. Missing volumes are unavailable.</p>
        <p class="source-row">${badge('gl_data.json')} ${badge('production_daily_sheets.json (DB)')}</p>
        <div class="cost-stage-grid">${stageCards}</div>
        ${card(`Coal Mining Cost by Stage ${badge('gl_data.json')} ${badge('production_daily_sheets.json')}`, table(['Mining Activity', 'From Valuation to (USD)', 'From Costing to (USD)', 'Total Cost (USD)', 'Operating Volume (MT)', 'Stage Cost / MT', 'Cost / MT Total Production'], rows))}
        ${card(`Monthly Cost Comparison <span class="subtle">${esc(data.comparisonPeriod || 'previous month')}</span>`, table(['Stage', 'Previous Cost / MT', 'Current Cost / MT', 'Change', 'Change %'], (data.comparison || []).map(item => `<tr><td>${esc(item.stage)}</td><td class="num">${item.previousCostPerMT == null ? 'USD —' : `USD ${detailMoney(item.previousCostPerMT)}`}</td><td class="num">${item.currentCostPerMT == null ? 'USD —' : `USD ${detailMoney(item.currentCostPerMT)}`}</td><td class="num ${item.change > 0 ? 'negative' : 'positive'}">${item.change == null ? 'USD —' : `USD ${detailMoney(item.change)}`}</td><td class="num">${item.changePct == null ? '—' : `${Number(item.changePct).toFixed(2)}%`}</td></tr>`)))}, 'cost-analysis-card')}
        ${card(`DB Cost Summary by Activity ${badge(dbSummary.dataSource)}`, table(['Entity', 'Activity', 'Component', 'Amount USD'], (dbSummary.rows || []).filter(row => Number(row.amount || 0) !== 0).map(row => `<tr><td>${esc(row.entity)}</td><td>${esc(row.activity)}</td><td class="center">${esc(row.component)}</td><td class="num">${money(row.amount)}</td></tr>`)), 'cost-analysis-card')}`;
}

async function inventory(period, mode) {
    const data = await api(`/api/inventory-valuation?period=${period}&mode=${mode}`);
    const stages = data.stages || [];
    const totalQty = Number(data.totalEndingQtyMT || stages.reduce((total, stage) => total + Number(stage.endingQtyMT || 0), 0));
    const totalValue = Number(data.totalEndingValueUSD || stages.reduce((total, stage) => total + Number(stage.endingValueUSD || 0), 0));
    const cards = stages.map(stage => `<article class="inventory-stage"><h3>${esc(stage.name)}</h3><p><span>Closing Qty</span><b>${qty(stage.endingQtyMT)} MT</b></p><p><span>Closing Value</span><b>${money(stage.endingValueUSD)}</b></p><p><span>Moving Average</span><b>${money(stage.endingRateUSD)} / MT</b></p></article>`).join('');
    const rows = stages.flatMap(stage => (stage.rows || []).map(row => `<tr><td>${esc(stage.name)}</td><td class="num">${qty(row.beginningQtyMT)}</td><td class="num">${money(row.beginningValueUSD)}</td><td class="num">${qty(row.availableQtyMT)}</td><td class="num">${money(row.availableValueUSD)}</td><td class="num">${qty(row.inflowQtyMT)}</td><td class="num">${money(row.inflowTotalValueUSD ?? row.receiptValueUSD)}</td><td class="num">${money(row.addedCostUSD)}</td><td class="num">${qty(row.outflowQtyMT)}</td><td class="num">${money(row.outflowValueUSD)}</td><td class="num">${qty(row.adjustmentQtyMT)}</td><td class="num">${money(row.adjustmentValueUSD)}</td><td class="num">${qty(row.endingQtyMT)}</td><td class="num">${money(row.endingValueUSD)}</td><td class="num">${money(row.endingRateUSD)}</td></tr>`));
    const routeTotals = {};
    stages.flatMap(stage => stage.rows || []).filter(row => row.type === 'outflow').forEach(row => {
        const key = row.label || row.source || 'Other outflow';
        routeTotals[key] = (routeTotals[key] || 0) + Number(row.qtyMT || 0);
    });
    const routeRows = Object.entries(routeTotals).map(([label, value]) => `<tr><td>${esc(label)}</td><td class="num">${qty(Math.abs(value))}</td><td>${value < 0 ? 'Outflow' : 'Inflow'}</td></tr>`);
    const bua = (data.buaHistory || []).find(item => item.period === data.period);
    const buaRows = (bua?.rows || []).map(row => `<tr><td>${esc(row.label)}</td><td class="num">${qty(row.qtyMT)}</td><td class="num">${money(row.valueUSD)}</td><td class="num">${money(row.rateUSD)}</td><td>${esc(row.type)}</td></tr>`);
    const flowRows = stages.map(stage => `<tr><td>${esc(stage.name)}</td><td class="num">${qty(stage.inflowQtyMT)}</td><td class="num">${qty(stage.outflowQtyMT)}</td><td class="num">${qty(stage.adjustmentQtyMT)}</td><td class="num">${qty(stage.endingQtyMT)}</td><td class="num">${money(stage.endingValueUSD)}</td></tr>`);
    const colors = ['#2563eb', '#0891b2', '#7c3aed', '#eab308', '#0f766e', '#f97316', '#64748b', '#b45309'];
    const totalIncludedQty = stages.filter(stage => stage.includedInQuantityTotal !== false).reduce((sum, stage) => sum + Number(stage.endingQtyMT || 0), 0);
    let offset = 0;
    const circumference = 2 * Math.PI * 108;
    const segments = stages.filter(stage => stage.includedInQuantityTotal !== false && Number(stage.endingQtyMT) > 0).map((stage, index) => {
        const length = Number(stage.endingQtyMT) / totalIncludedQty * circumference;
        const segment = `<circle cx="140" cy="140" r="108" fill="none" stroke="${colors[index % colors.length]}" stroke-width="32" stroke-dasharray="${length} ${circumference - length}" stroke-dashoffset="${-offset}" transform="rotate(-90 140 140)"><title>${esc(stage.name)} ${qty(stage.endingQtyMT)} MT</title></circle>`;
        offset += length;
        return segment;
    }).join('');
    const donut = `<div class="inventory-donut-layout"><svg viewBox="0 0 280 280" class="inventory-donut" role="img" aria-label="Coal stock composition ${esc(period)}"><circle cx="140" cy="140" r="108" fill="none" stroke="#e2e8f0" stroke-width="32"/>${segments}<text x="140" y="123" text-anchor="middle" fill="#64748b" font-size="12">Total volume</text><text x="140" y="153" text-anchor="middle" fill="#0f172a" font-size="22" font-weight="700">${qty(totalIncludedQty)}</text><text x="140" y="174" text-anchor="middle" fill="#64748b" font-size="12">MT</text></svg><div class="inventory-chart-legend">${stages.map((stage, index) => `<div><i style="background:${colors[index % colors.length]}"></i><span>${esc(stage.name)}</span><b>${money(stage.endingRateUSD)} / MT</b><strong>${qty(stage.endingQtyMT)}</strong></div>`).join('')}</div></div>`;
    $('#pageContent').innerHTML = `
        ${sectionHeader('Monthly Inventory Valuation', 'Eight locations • Qty MT • Value USD • Monthly weighted average per stock.', `<label>Month <input type="month" value="${period}" disabled></label>${unavailable('First Period Opening Balance')}`)}
        <p class="inventory-notice">MTD movements and closing balances for ${esc(period)}.</p>
        <p class="inventory-notice">Coal mined uses Google Sheets for ${esc(period)}. Other movements follow their indicated sources. Costs use temporary Excel history; uploading GL for a month replaces that month’s costs. Hauling allocation incomplete: ${esc(period)}.</p>
        <p class="source-row">${badge('inventory_model.json')} ${badge('inventory_movements.json')} ${badge('inventory_movements_dummy.json')} ${badge('inventory_historical_costs.json')} ${badge('gl_data.json')}</p>
        <div class="inventory-total-card"><h3>Total Stock Coal — ${esc(period)}</h3><strong>${qty(totalQty)} MT</strong><span>${money(totalValue)}</span><small>Provisional value — see quantity and cost sources</small></div>
        <div class="inventory-location-grid">${cards}</div>
        ${card(`Coal Stock Composition — ${period} ${badge('inventory_movements.json')}`, `<p class="inventory-chart-caption">Slices represent closing stock volume (MT) by location.</p>${donut}`)}
        ${card(`Inventory Balances and Movements ${badge('inventory_model.json')} ${badge('gl_data.json')}`, table(['Stage', 'Opening MT', 'Opening USD', 'Available MT', 'Available USD', 'Inflow MT', 'Inflow Value USD', 'Added Cost USD', 'Outflow MT', 'Outflow USD', 'Adjustment MT', 'Adjustment USD', 'Closing MT', 'Closing USD', 'Rate Closing USD/MT'], rows))}
        ${card(`Hauling Route Summary ${badge(data.costMapping?.source || 'inventory_model.json')}`, table(['Route / Movement', 'Quantity MT', 'Direction'], routeRows))}
        ${card(`Stockpile Flow Summary — ${period}`, table(['Stockpile', 'Inflow MT', 'Outflow MT', 'Adjustment MT', 'Closing MT', 'Closing USD'], flowRows))}
        ${card(`BUA Monthly History — ${period}`, table(['Movement', 'Quantity MT', 'Value USD', 'Rate USD/MT', 'Type'], buaRows))}
        ${card('Closing Balance History — rolled forward from first period', table(['Month', 'Total Stock MT', 'Stock Value USD', 'COGS USD', 'Cost Data'], (data.history || []).map(item => `<tr><td>${esc(item.period)}</td><td class="num">${qty(item.totalEndingQtyMT)}</td><td class="num">${money(item.totalEndingValueUSD)}</td><td class="num">${money(item.cogsShippedValUSD)}</td><td>${esc(item.costSource)}</td></tr>`)))}`;
}

async function financial(period, mode) {
    const data = await api(`/api/financial-statements?period=${period}&mode=${mode}`);
    const report = data.report || {};
    const periods = report.periods || Object.keys(report.profitLoss?.[0]?.months || {});
    const formatStatementAmount = value => value == null ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 0, minimumFractionDigits: 0 }).replace(/^-/, '(') + (Number(value) < 0 ? ')' : '');
    const reportTable = lines => {
        const headers = ['Description', 'YTD 25', 'YTD 26', ...periods];
        const rows = (lines || []).map(line => {
            const classes = [line.kind === 'heading' ? 'financial-subtotal' : '', line.kind === 'total' ? 'financial-final' : ''].filter(Boolean).join(' ');
            const cells = [
                `<td>${esc(line.label || line.id)}</td>`,
                `<td class="num financial-ytd">${formatStatementAmount(line.comparative)}</td>`,
                `<td class="num financial-ytd">${formatStatementAmount(line.ytd)}</td>`,
                ...periods.map(month => `<td class="num ${month === period ? 'selected-period' : ''}">${formatStatementAmount(line.months?.[month])}</td>`),
            ];
            return `<tr class="${classes}">${cells.join('')}</tr>`;
        });
        return `<div class="table-responsive"><table class="table-accounting financial-report-table"><thead><tr>${headers.map((header, index) => `<th class="${index === 0 ? 'financial-label' : index < 3 ? 'financial-ytd' : header === period ? 'selected-period' : ''}">${esc(header)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.join('') : `<tr><td colspan="${headers.length}">No data available.</td></tr>`}</tbody></table></div>`;
    };
    const income = data.incomeStatement || {};
    const reconciliation = data.inventoryReconciliation || {};
    const diagnostics = data.diagnostics || {};
    const accountRows = (data.accountBalances || []).map(row => `<tr><td>${esc(row.code)}</td><td>${esc(row.name)}</td><td>${esc(row.mapping)}</td><td class="num">${money(row.baseUSD)}</td><td class="num">${money(row.glMovementUSD)}</td><td class="num">${money(row.closingUSD)}</td></tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Financial Statements', 'Profit and loss and balance sheet — September baseline plus GL movements from October onward. Amounts in USD. Baseline through 2026-09. Monthly GL movements are added from 2026-10. Finance and FX follow the financial statement format.', `<label>Month <input type="month" value="${period}" disabled></label>`)}
        <p class="financial-notice">MTD P&amp;L for ${esc(period)}; balance sheet is the closing position as of that month.</p>
        <p class="financial-notice">${esc(period)} GL rows. Accounts requiring mapping: ${(diagnostics.unmappedAccounts || []).length}. Baseline difference: ${money(diagnostics.baselineBalanceDifferenceUSD ?? diagnostics.balanceDifferenceUSD)}.</p>
        <p class="source-row">${badge('financial_baseline.json')} ${badge('gl_data.json')}</p>
        <div class="kpi-grid"><article class="kpi-card blue"><span class="kpi-title">TOTAL REVENUE</span><strong class="kpi-value">${money(income.revenue?.totalRevenue)}</strong><small class="kpi-sub">${esc(period)} ${esc(mode).toUpperCase()}</small></article><article class="kpi-card cost"><span class="kpi-title">EBITDA</span><strong class="kpi-value">${money(income.operatingProfitEBITDA)}</strong><small class="kpi-sub">Operating profit before tax</small></article><article class="kpi-card success"><span class="kpi-title">NET PROFIT AFTER TAX</span><strong class="kpi-value">${money(income.netProfitAfterTax)}</strong><small class="kpi-sub">Baseline financial statement</small></article></div>
        ${card(`${esc(companyName)} — Profit & Loss Statement (USD)`, reportTable(report.profitLoss || data.sections?.profitLoss), 'financial-reference-card')}
        ${card(`${esc(companyName)} — Balance Sheet (USD)`, reportTable(report.balanceSheet || data.sections?.balanceSheet), 'financial-reference-card')}
        ${card('Balances by Account', table(['Account', 'Name', 'Mapping', 'Base USD', 'GL Movement USD', 'Closing USD'], accountRows), 'financial-accounts-card')}
        ${card('Financial Statement Diagnostics', `<div class="diagnostic-grid"><p><span>Data source</span><b>${esc(data.dataSource)}</b></p><p><span>Period</span><b>${esc(data.period)} ${esc(data.mode).toUpperCase()}</b></p><p><span>Balance difference</span><b>${money(diagnostics.balanceDifferenceUSD)}</b></p><p><span>Unmapped accounts</span><b>${qty((diagnostics.unmappedAccounts || []).length)}</b></p><p><span>Inventory book value</span><b>${money(reconciliation.bookInventoryUSD)}</b></p><p><span>Coal inventory value</span><b>${money(reconciliation.coalInventoryUSD)}</b></p><p><span>Cash flow</span><b>${data.cashFlowEnabled ? 'Enabled' : 'Not available'}</b></p></div>`)}`;
}

async function integration() {
    const data = await api('/api/integration-status');
    const connector = (name, detail, status, source, lines, button) => `<article class="connector-card"><div><div class="connector-heading"><h3>${name}</h3>${badge(source)}</div><p>${detail}</p><div class="connector-details">${lines.map(line => `<div>${line}</div>`).join('')}</div>${button}</div><div><span class="status ${status === 'online' ? 'online' : 'manual'}">${esc(status === 'online' ? 'Online' : 'Manual Sync')}</span></div></article>`;
    $('#pageContent').innerHTML = `
        ${sectionHeader('Integration & Real-Time Data Sources', 'Microsoft Dynamics 365 and Google Sheets production sources.')}
        <div class="connector-grid">${connector('Microsoft Dynamics 365 OData Connector', 'Fetches General Ledger transactions incrementally in near real time.', data.d365.status, 'config.json', [`<b>Entity Target:</b> GeneralJournalAccountEntries`, `<b>Source:</b> ${esc(data.d365.mode)}`, `<b>Records:</b> ${qty(data.database.glEntries)} GL records`], '<button id="viewGlData" class="btn btn-primary" type="button">View GL Data</button>')}${connector('Google Sheets Production Connector', 'Reads monthly production activities from DB and daily Coal Getting Actual from Produksi.', data.googleSheets.status, 'production_daily_sheets.json', [`<b>Source unit:</b> MT`, `<b>Source:</b> ${esc(data.googleSheets.mode)}`, `<b>Metrics:</b> ${qty(data.database.monthlyMetrics)} records`], unavailable('Sync Production Now'))}</div>
        ${card('Accrual Input — Add to Report Cost', `<p class="integration-note">Accrual adds amount to Report Cost and Cost/MT for the selected period. Entry form remains available only after authentication is enabled.</p>${unavailable('Add Accrual')}`)}
        ${card('Data Source Summary', table(['Source', 'Status', 'Detail'], [`<tr><td>D365 GL</td><td><span class="status online">${esc(data.d365.status)}</span></td><td>${esc(data.d365.mode)} • ${qty(data.database.glEntries)} GL records</td></tr>`, `<tr><td>Google Sheets</td><td><span class="status manual">${esc(data.googleSheets.status)}</span></td><td>${esc(data.googleSheets.mode)} • ${qty(data.database.monthlyMetrics)} metrics</td></tr>`, `<tr><td>Frozen Baseline</td><td><span class="status verified">verified</span></td><td>Data freeze ${esc(data.dataFreeze)}</td></tr>`]))}`;
    $('#viewGlData').addEventListener('click', () => viewGlData($('#period').value));
}

async function viewGlData(period) {
    const data = await api(`/api/gl-data?period=${period}`);
    const rows = (data.rows || []).map(row => `<tr><td>${esc(row.entry_date)}</td><td>${esc(row.journal_number)}</td><td>${esc(row.main_account)}</td><td>${esc(row.account_name)}</td><td>${esc(row.source_location || row.valuation_to)}</td><td>${esc(row.costing_to)}</td><td class="num">${money(row.amount_reporting)}</td></tr>`);
    $('#pageContent').innerHTML = `${sectionHeader('D365 GL Data — Local Snapshot', `Read-only preview of ${data.limit} latest rows for ${period}.`, '<button id="backIntegration" class="btn btn-outline" type="button">← Back</button>')}${card(`${badge('gl_entries')} ${data.rows.length} rows`, table(['Date', 'Journal', 'Account', 'Account Name', 'Location', 'Costing To', 'Amount USD'], rows, 'gl-preview-table'))}`;
    $('#backIntegration').addEventListener('click', integration);
}

async function render() {
    const period = $('#period').value;
    const mode = $('#mode').value;
    updateNavigation(period, mode);
    $('#pageContent').innerHTML = '<div class="loading">Loading dashboard data…</div>';
    try {
        if (state.view === 'overview') await overview(period, mode);
        else if (state.view === 'cost') await cost(period, mode);
        else if (state.view === 'variance') await variance(period, mode);
        else if (state.view === 'metric') await metric(period, mode);
        else if (state.view === 'inventory') await inventory(period, mode);
        else if (state.view === 'financial') await financial(period, mode);
        else await integration();
    } catch (error) {
        console.error(error);
        $('#pageContent').innerHTML = `<section class="table-card error-card"><h2>Data cannot be loaded</h2><p>${esc(error.message)}</p></section>`;
    }
}

document.querySelectorAll('.tabs a').forEach(tab => tab.addEventListener('click', event => {
    event.preventDefault();
    state.view = tab.dataset.view;
    render();
}));
$('#period').addEventListener('change', render);
$('#mode').addEventListener('change', render);
render();
