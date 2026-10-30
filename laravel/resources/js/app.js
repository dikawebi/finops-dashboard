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
const table = (headers, rows, extra = '') => { const body = Array.isArray(rows) ? rows.join('') : String(rows || ''); return `<div class="table-responsive"><table class="table-accounting ${extra}"><thead><tr>${headers.map(header => `<th>${esc(header)}</th>`).join('')}</tr></thead><tbody>${body.length ? body : `<tr><td colspan="${headers.length}">No data available.</td></tr>`}</tbody></table></div>`; };
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
    const [cost, financial, inventory, production, costPerMt] = await Promise.all([
        api(`/api/cost-matrix?period=${period}&mode=${mode}`),
        api(`/api/financial-statements?period=${period}&mode=${mode}`),
        api(`/api/inventory-valuation?period=${period}&mode=${mode}`),
        api(`/api/production-summary?period=${period}&mode=${mode}`),
        api(`/api/cost-per-mt?period=${period}&mode=${mode}`),
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
    const topDrivers = (costPerMt.costByAccountGroup || []).slice().sort((a, b) => Number(b.amount) - Number(a.amount)).slice(0, 8).map(item => { const percentage = Number(cost.grandTotal.allCost) ? Number(item.amount) / Number(cost.grandTotal.allCost) * 100 : 0; return `<div class="driver-row"><div><span>${esc(item.group)}</span><div class="driver-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percentage.toFixed(1)}" aria-label="${esc(item.group)} ${percentage.toFixed(1)} percent"><i style="width:${Math.min(100, Math.max(1, percentage))}%"></i></div></div><b>${money(item.amount)} (${percentage.toFixed(0)}%)</b></div>`; }).join('');
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
    const allAccountsActive = !state.costSearch && !state.costParentOnly && !state.costHideZero;
    const unallocatedRows = data.rows.filter(row => !row.isParent && Math.abs(Number(row.unallocatedHauling || 0)) > 0.000001);
    const unallocatedTotal = unallocatedRows.reduce((sum, row) => sum + Number(row.unallocatedHauling || 0), 0);
    const rows = visibleRows.map(row => `<tr class="${row.isParent ? 'parent-row' : ''}"><td>${esc(row.code)}</td><td class="account-name">${row.isParent ? `<button type="button" class="account-toggle" data-toggle-parent="${esc(row.code)}" aria-label="${state.costCollapsed[row.code] ? 'Expand' : 'Collapse'} ${esc(row.name)}">${state.costCollapsed[row.code] ? '+' : '−'}</button>` : ''}${esc(row.name)}</td><td class="center">-</td><td class="num">${reportAmount(row.allCost)}</td>${fields.map(([field], index) => `<td class="num ${index === 6 ? 'direct-start' : ''}">${reportAmount(row[field])}</td>`).join('')}</tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Report Cost: COGP & Direct Expense', 'Accounts beginning 7–9 are allocated to GA. Accounts beginning 5–6 follow Costing to; Direct Expense is allocated by Valuation to, with Not Valuated in GA. Selling Expense has its own column. Amounts in USD.', `<label class="period-control">Month <input id="costPeriod" type="month" value="${period}" aria-label="Report cost period"></label><input id="costSearch" class="input-search" placeholder="🔍 Search account code / name..." value="${esc(state.costSearch)}"><button id="costShowAll" class="btn btn-outline ${allAccountsActive ? 'is-active' : ''}" type="button" aria-pressed="${allAccountsActive}">Show All Accounts</button><button id="costParentOnly" class="btn btn-outline ${state.costParentOnly ? 'is-active' : ''}" type="button" aria-pressed="${state.costParentOnly}">${state.costParentOnly ? 'Show All Rows' : 'Parent Accounts Only (Summary)'}</button><button id="costHideZero" class="btn btn-outline ${state.costHideZero ? 'is-active' : ''}" type="button" aria-pressed="${state.costHideZero}">${state.costHideZero ? 'Show Zero Balances' : 'Hide Zero Balances'}</button><button id="costDeleteGl" class="btn btn-outline" type="button">Delete GL Data</button><button id="costExport" class="btn btn-primary" type="button">📥 Export Excel/CSV</button>`)}
        <div class="cost-report-card"><p class="table-note">Showing ${visibleRows.length} of ${data.rows.length} accounts</p><div class="cost-notice">Source: uploaded GL for the selected period.</div>${unallocatedRows.length ? `<div class="cost-notice">Coal Hauling has no route for ${unallocatedRows.length} accounts (net USD ${Math.round(Math.abs(unallocatedTotal)).toLocaleString('en-US')}). The balance remains in ALL Cost. Upload GL with Costing to: Coal Hauling BUA to WP, Coal Hauling Bua to Port, or Coal Hauling WP to Port.</div>` : ''}<div class="table-responsive"><table class="table-accounting cost-report-table"><thead><tr><th rowspan="2">AccountCode</th><th rowspan="2">AccountName</th><th rowspan="2">Check</th><th rowspan="2" class="all-cost-header">ALL Cost</th><th colspan="6" class="group-header">COGP</th><th colspan="6" class="group-header direct-group">Direct Expense</th></tr><tr>${fields.map(([field, label], index) => `<th class="${index === 6 ? 'direct-start' : ''}">${esc(label)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody><tfoot><tr><th colspan="3">Grand Total</th><th class="num">${reportAmount(data.grandTotal.allCost)}</th>${fields.map(([field], index) => `<th class="num ${index === 6 ? 'direct-start' : ''}">${reportAmount(data.grandTotal[field])}</th>`).join('')}</tr></tfoot></table></div></div>`;
    $('#costPeriod').addEventListener('change', event => { $('#period').value = event.target.value; render(); });
    $('#costSearch').addEventListener('input', event => { state.costSearch = event.target.value; cost(period, mode); });
    $('#costShowAll').addEventListener('click', () => { state.costSearch = ''; state.costParentOnly = false; state.costHideZero = false; state.costCollapsed = {}; cost(period, mode); });
    $('#costParentOnly').addEventListener('click', () => { state.costParentOnly = !state.costParentOnly; cost(period, mode); });
    $('#costHideZero').addEventListener('click', () => { state.costHideZero = !state.costHideZero; cost(period, mode); });
    $('#costDeleteGl').addEventListener('click', async () => {
        if (!confirm('Delete all GL data? This action cannot be undone.')) return;
        const response = await fetch('/api/gl-data', { method: 'DELETE' });
        if (!response.ok) { alert('GL data could not be deleted. Authentication may be required.'); return; }
        await render();
    });
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
    const controls = `<div class="report-source-row">${badge('gl_data.json')} ${badge('cost_variance_remarks.json')}</div><div class="variance-controls"><label>Current month <input id="variancePeriod" type="month" value="${period}"></label><label>Location / category <select id="varianceLocation">${varianceOptions.map(([value, label]) => `<option value="${value}" ${state.varianceLocation === value ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label><label>Search account <input id="varianceSearch" type="search" placeholder="Account code or name" value="${esc(state.varianceSearch)}"></label><label class="variance-checkbox"><input id="varianceHideZero" type="checkbox" ${state.varianceHideZero ? 'checked' : ''}> Hide zero balances</label></div><p class="variance-note">MTD: ${esc(period)} vs ${esc(data.previousPeriod)}. ${esc(data.previousPeriod)}: workbook; ${esc(period)}: GL. Variance = current − previous; % uses the absolute previous balance. Zero or unavailable base is shown as —. Remarks are saved by month, category and account.</p><p class="table-note">${visibleRows.length} rows • Comparison ${data.previousPeriod} → ${data.period}</p>`;
    const varianceTotals = { previous: Number(data.previous?.grandTotal?.[state.varianceLocation] ?? 0), current: Number(data.current?.grandTotal?.[state.varianceLocation] ?? 0) };
    varianceTotals.change = varianceTotals.current - varianceTotals.previous;
    const varianceTotalPct = varianceTotals.previous === 0 ? null : varianceTotals.change / Math.abs(varianceTotals.previous) * 100;
    const varianceTable = `<div class="table-responsive"><table class="table-accounting variance-table"><thead><tr>${['Account', 'Account Name', 'Previous month', 'Current month', 'Variance USD', 'Variance %', 'Remark'].map(header => `<th>${esc(header)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.join('') : `<tr><td colspan="7">No data available.</td></tr>`}</tbody><tfoot><tr><th colspan="2">Total</th><th class="num">${reportAmount(varianceTotals.previous)}</th><th class="num">${reportAmount(varianceTotals.current)}</th><th class="num ${varianceTotals.change > 0 ? 'negative' : 'positive'}">${reportAmount(varianceTotals.change)}</th><th class="num">${varianceTotalPct == null ? '—' : `${varianceTotalPct.toFixed(3)}%`}</th><th></th></tr></tfoot></table></div>`;
    $('#pageContent').innerHTML = `${sectionHeader('Cost Variance Analysis', 'Compare monthly cost by account and location; record explanations for each variance.', `<button id="varianceExcel" class="btn btn-outline" type="button">📊 Export Excel</button>`)}${card(controls, varianceTable)}`;
    $('#variancePeriod').addEventListener('change', event => { $('#period').value = event.target.value; render(); });
    $('#varianceSearch').addEventListener('input', event => { state.varianceSearch = event.target.value; const cursor = event.target.selectionStart; variance(period, mode).then(() => { const input = $('#varianceSearch'); if (input) { input.focus(); input.setSelectionRange(cursor, cursor); } }); });
    $('#varianceHideZero').addEventListener('change', event => { state.varianceHideZero = event.target.checked; variance(period, mode); });
    $('#varianceCategory')?.addEventListener('change', event => { state.varianceCategory = event.target.value; variance(period, mode); });
    $('#varianceLocation').addEventListener('change', event => { state.varianceLocation = event.target.value; variance(period, mode); });
    $('#varianceExcel').addEventListener('click', () => downloadExcel(`cost-variance-${period}-${mode}.xls`, ['Account', 'Account Name', 'Previous month', 'Current month', 'Variance USD', 'Variance %', 'Remark'], visibleRows.map(row => [row.code, row.name, row.previous, row.current, row.change, row.changePct == null ? '' : row.changePct, row.remark || ''])));
}

async function metric(period, mode) {
    const previousPeriod = (() => { const date = new Date(`${period}-01T00:00:00Z`); date.setUTCMonth(date.getUTCMonth() - 1); return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`; })();
    const [data, dbSummary, excel, production, previousProduction] = await Promise.all([api(`/api/cost-per-mt?period=${period}&mode=${mode}`), api(`/api/db-cost-summary?period=${period}&mode=${mode}`), api('/api/excel-cost-example'), api(`/api/production-summary?period=${period}&mode=${mode}`), api(`/api/production-summary?period=${previousPeriod}&mode=${mode}`)]);
    const stageCards = data.stages.map(stage => `<article class="stage-card"><span>${esc(stage.stage)}</span><strong>${stage.costPerMT == null ? 'USD —' : `USD ${detailMoney(stage.costPerMT)}`}</strong><small>per stage MT (${qty(stage.metricTons)} MT)</small><em>Total Cost: ${money(stage.totalCost)} (USD)</em></article>`).join('');
    const rows = data.stages.map(stage => `<tr><td><strong>${esc(stage.stage)}</strong></td><td class="num">${reportAmount(stage.valuationCost)}</td><td class="num">${reportAmount(stage.operatingCost)}</td><td class="num">${reportAmount(stage.totalCost)}</td><td class="num">${qty(stage.metricTons)} MT</td><td class="num stage-rate">${stage.costPerMT == null ? 'USD —' : `USD ${detailMoney(stage.costPerMT)}`}</td><td class="num">${stage.costPerTotalMT == null ? 'USD —' : `USD ${detailMoney(stage.costPerTotalMT)}`}</td></tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Cost per Metric Ton Analysis', 'D365 accounting costs and Google Sheets weighbridge volume')}
        <p class="metric-notice">Stage rates use DB volumes: Coal Getting, the three hauling routes, Washing Plant Feed, and Port Loading. Missing volumes are unavailable.</p>
        <p class="source-row">${badge('gl_data.json')} ${badge('production_daily_sheets.json (DB)')}</p>
        <div class="cost-stage-grid">${stageCards}</div>
        ${card(`Coal Mining Cost by Stage ${badge('gl_data.json')} ${badge('production_daily_sheets.json')}`, table(['Mining Activity', 'From Valuation to (USD)', 'From Costing to (USD)', 'Total Cost (USD)', 'Operating Volume (MT)', 'Stage Cost / MT', 'Cost / MT Total Production'], rows))}
        ${monthlyPerformance(period, excel, dbSummary, { [period]: production, [previousPeriod]: previousProduction })}
        ${dbSummaryMarkup(dbSummary, period)}`;
    bindDbSummary(dbSummary);
}

const dbSummaryState = { start: '2026-01', end: '2026-10', view: 'all', detail: 'summary', entity: '' };

function dbSummaryMarkup(summary, period) {
    dbSummaryState.end = period;
    const entities = [...new Set((summary.rows || []).map(row => row.entity).filter(Boolean))].sort();
    return `<section class="table-card cost-analysis-card db-summary-card"><span class="cost-eyebrow">COST DETAIL</span><h3>DB Cost Summary by Activity</h3><p>Monthly USD amounts from the DB Summary. Select Entity Breakdown for details. Remarks are saved for each displayed group.</p><div class="section-actions db-summary-controls"><label>Start month <input type="month" id="db-cost-start" value="${esc(dbSummaryState.start)}"></label><label>End month <input type="month" id="db-cost-end" value="${esc(dbSummaryState.end)}"></label><label>View <select id="db-cost-view"><option value="all">All months</option><option value="compare">Start vs end</option></select></label><label>Detail <select id="db-cost-detail"><option value="summary">Activity Summary</option><option value="entity">Entity Breakdown</option></select></label><label id="db-cost-entity-label" hidden>Entity <select id="db-cost-entity"><option value="">All entities</option>${entities.map(entity => `<option value="${esc(entity)}">${esc(entity)}</option>`).join('')}</select></label><button id="db-cost-refresh" class="btn btn-outline" type="button">Refresh</button></div><p id="db-cost-status"></p><div class="table-responsive"><table class="table-accounting"><thead id="db-cost-head"></thead><tbody id="db-cost-body"></tbody></table></div></section>`;
}

function bindDbSummary(summary) {
    const controls = ['db-cost-start', 'db-cost-end', 'db-cost-view', 'db-cost-detail', 'db-cost-entity'];
    controls.forEach(id => document.getElementById(id)?.addEventListener('change', event => {
        const key = id.replace('db-cost-', '');
        if (key === 'start' || key === 'end') dbSummaryState[key] = event.target.value;
        if (key === 'view' || key === 'detail' || key === 'entity') dbSummaryState[key] = event.target.value;
        renderDbSummary(summary);
    }));
    document.getElementById('db-cost-refresh')?.addEventListener('click', () => renderDbSummary(summary));
    renderDbSummary(summary);
}

function renderDbSummary(summary) {
    const start = document.getElementById('db-cost-start')?.value || dbSummaryState.start;
    const end = document.getElementById('db-cost-end')?.value || dbSummaryState.end;
    const head = document.getElementById('db-cost-head');
    const body = document.getElementById('db-cost-body');
    const status = document.getElementById('db-cost-status');
    if (!head || !body || !status) return;
    if (!start || !end || start > end) { status.textContent = 'Choose a valid start and end month.'; head.innerHTML = ''; body.innerHTML = ''; return; }
    const months = [];
    let [year, month] = start.split('-').map(Number);
    while (`${year}-${String(month).padStart(2, '0')}` <= end && months.length < 120) { months.push(`${year}-${String(month).padStart(2, '0')}`); if (++month === 13) { month = 1; year += 1; } }
    const compare = dbSummaryState.view === 'compare';
    const shown = compare && months.length > 1 ? [months[0], months[months.length - 1]] : months;
    const breakdown = dbSummaryState.detail === 'entity';
    const entity = breakdown ? (dbSummaryState.entity || '') : '';
    const groups = new Map();
    (summary.rows || []).forEach(row => {
        if (entity && row.entity !== entity) return;
        const id = breakdown ? JSON.stringify(['entity', row.entity, row.activity]) : JSON.stringify(['activity', row.activity]);
        if (!groups.has(id)) groups.set(id, { id, entity: row.entity, activity: row.activity, items: [], months: {} });
        groups.get(id).items.push(row);
    });
    const rows = [...groups.values()];
    rows.forEach(row => months.forEach(monthKey => {
        const values = row.items.map(item => item.months?.[monthKey]).filter(value => Number.isFinite(Number(value))).map(Number);
        if (values.length) row.months[monthKey] = values.reduce((total, value) => total + value, 0);
    }));
    const monthLabel = value => { const [y, m] = value.split('-').map(Number); return new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('en-US', { month: 'short', year: '2-digit', timeZone: 'UTC' }).replace(',', ''); };
    head.innerHTML = `<tr>${breakdown ? '<th>Entity</th>' : ''}<th>Activity</th>${shown.map((monthKey, index) => `<th>${monthLabel(monthKey)} (USD)</th>${index ? '<th>Change %</th>' : ''}`).join('')}<th>Remark</th><th></th></tr>`;
    body.innerHTML = rows.map((row, index) => {
        const cells = shown.map((monthKey, i) => {
            const value = row.months[monthKey];
            const previous = i ? row.months[shown[i - 1]] : null;
            const percent = Number.isFinite(value) && Number.isFinite(previous) && previous !== 0 ? (value - previous) / Math.abs(previous) * 100 : null;
            const color = percent > 0 ? '#dc2626' : percent < 0 ? '#15803d' : '';
            return `<td class="num">${Number.isFinite(value) ? Number(value).toLocaleString('en-US', { maximumFractionDigits: 0 }) : '—'}</td>${i ? `<td class="num"${color ? ` style="color:${color};font-weight:700"` : ''}>${percent === null ? '—' : `${detailMoney(percent)}%`}</td>` : ''}`;
        }).join('');
        const remark = summary.remarks?.[row.id] || '';
        return `<tr>${breakdown ? `<td>${esc(row.entity)}</td>` : ''}<td>${esc(row.activity)}</td>${cells}<td><input type="text" maxlength="2000" data-db-remark="${index}" value="${esc(remark)}" aria-label="Remark"></td><td><button type="button" class="btn btn-outline" data-db-save="${index}">Save</button></td></tr>`;
    }).join('');
    status.textContent = rows.length ? `From October 2026, uploaded GL months use Direct Expense Mining and GA for BP overhead; Direct Expense Washing Plant and BP PORT are added to Washplant and Loading BLC. GL months: ${(summary.glOverheadPeriods || []).join(', ') || 'none'}. Change % compares displayed months; a zero or unavailable base is —.` : 'No DB Summary data. Use Sync Production Now in Integration & Data Sources.';
    document.getElementById('db-cost-entity-label').hidden = !breakdown;
    body.onclick = async event => {
        const button = event.target.closest('[data-db-save]');
        if (!button) return;
        const index = Number(button.dataset.dbSave), row = rows[index], input = body.querySelector(`[data-db-remark="${index}"]`);
        if (!row || !input) return;
        button.disabled = true;
        try {
            const response = await fetch('/api/db-cost-summary/remark', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: row.id, remark: input.value }) });
            if (!response.ok) throw new Error('Save failed');
            summary.remarks = summary.remarks || {};
            summary.remarks[row.id] = input.value;
            button.textContent = 'Saved';
        } catch (error) { button.textContent = 'Retry'; } finally { button.disabled = false; }
    };
}

function monthlyPerformance(period, excel, dashboardSummary = null, productionByPeriod = {}) {
    const abcMonthly = (excel?.abcMonthly || []).map(row => ({ ...row }));
    const cogpMonthly = excel?.cogpMonthly || [];
    const date = new Date(`${period}-01T00:00:00Z`);
    const previousDate = new Date(date);
    previousDate.setUTCMonth(previousDate.getUTCMonth() - 1);
    const monthLabel = value => value.toLocaleDateString('en-US', { month: 'short', year: '2-digit', timeZone: 'UTC' }).replace(',', '');
    const currentMonth = date.toLocaleDateString('en-US', { month: 'short', timeZone: 'UTC' });
    const previousPeriod = `${previousDate.getUTCFullYear()}-${String(previousDate.getUTCMonth() + 1).padStart(2, '0')}`;
    const previousMonth = previousDate.toLocaleDateString('en-US', { month: 'short', timeZone: 'UTC' });
    const rowsFor = month => abcMonthly.filter(row => String(row.month).toLowerCase() === month.toLowerCase());
    const aggregate = (items, key) => {
        const groups = new Map();
        items.forEach(row => {
            const groupKey = key(row);
            if (!groups.has(groupKey)) groups.set(groupKey, { activity: row.activity, entity: row.entity, qty: 0, cost: 0, hasQty: false, hasCost: false });
            const item = groups.get(groupKey);
            if (Number.isFinite(Number(row.qty))) { item.qty += Number(row.qty); item.hasQty = true; }
            if (Number.isFinite(Number(row.cost))) { item.cost += Number(row.cost); item.hasCost = true; }
        });
        return groups;
    };
    const current = aggregate(rowsFor(currentMonth), row => row.activity);
    const previous = aggregate(rowsFor(previousMonth), row => row.activity);
    const rate = item => item?.hasQty && item?.hasCost && item.qty > 0 ? item.cost / item.qty : null;
    const calc = (item, old) => {
        const currentRate = rate(item), previousRate = rate(old), change = currentRate !== null && previousRate !== null ? currentRate - previousRate : null;
        return [currentRate, previousRate, change, change !== null && previousRate !== 0 ? change / Math.abs(previousRate) : null];
    };
    const formatCell = (value, percent = false, whole = false) => {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        if (whole) return Number(value).toLocaleString('en-US', { maximumFractionDigits: 0 });
        return `${detailMoney(percent ? Number(value) * 100 : value)}${percent ? '%' : ''}`;
    };
    const movementRows = [...new Set([...current.keys(), ...previous.keys()])].map(key => {
        const item = current.get(key) || previous.get(key);
        const values = calc(current.get(key), previous.get(key));
        return `<tr><td>${esc(item.activity)}</td><td class="num">${formatCell(item.hasQty ? item.qty : null, false, true)}</td><td class="num">${formatCell(item.hasCost ? item.cost : null, false, true)}</td><td class="num">${formatCell(values[0])}</td><td class="num">${formatCell(values[1])}</td><td class="num ${values[2] > 0 ? 'negative' : values[2] < 0 ? 'positive' : ''}">${formatCell(values[2])}</td><td class="num ${values[3] > 0 ? 'negative' : values[3] < 0 ? 'positive' : ''}">${formatCell(values[3], true)}</td><td>—</td></tr>`;
    });
    const counterpartCurrent = aggregate(rowsFor(currentMonth), row => JSON.stringify([row.activity, row.entity]));
    const counterpartPrevious = aggregate(rowsFor(previousMonth), row => JSON.stringify([row.activity, row.entity]));
    const counterpartRows = [...new Set([...counterpartCurrent.keys(), ...counterpartPrevious.keys()])].map(key => {
        const item = counterpartCurrent.get(key) || counterpartPrevious.get(key), values = calc(counterpartCurrent.get(key), counterpartPrevious.get(key));
        return `<tr><td>${esc(item.activity)}</td><td>${esc(item.entity)}</td><td class="num">${formatCell(values[0])}</td><td class="num">${formatCell(values[1])}</td><td class="num ${values[2] > 0 ? 'negative' : values[2] < 0 ? 'positive' : ''}">${formatCell(values[2])}</td><td class="num ${values[3] > 0 ? 'negative' : values[3] < 0 ? 'positive' : ''}">${formatCell(values[3], true)}</td><td>—</td></tr>`;
    });
    const volumeKeys = { 'Topsoil': 'topsoil', 'Mud': 'mud', 'Coal Getting': 'coalGetting', 'Coal Hauling Overdistance': 'coalGetting', 'Coal Loading at ROM to Port': 'loadingBuaPort', 'Coal Loading at ROM to WP': 'loadingBuaWp', 'Coal Loading at WP to Port': 'loadingWpPort', 'Coal Hauling ROM BUA to Port': 'haulingBuaPort', 'Coal Hauling ROM BUA to WP': 'haulingBuaWp', 'Coal Hauling WP to Port': 'haulingWpPort', 'Washplant': 'washCoal', 'Loading BLC': 'portLoading', 'ISP Rent': 'portToIsp', 'Transhipmet BP Port To Taboneo': 'portToTaboneo', 'Transhipmet BP Port To ISP': 'portToIsp', 'Transhipmet ISP to Taboneo': 'ispToTaboneo', 'Overhead BUA': 'coalGetting', 'OVH JKT & PC': 'coalGetting' };
    const applyDashboardSource = (targetPeriod, activities, entities) => {
        if (targetPeriod < '2026-10' || !dashboardSummary?.rows) return;
        const metrics = productionByPeriod[targetPeriod]?.dbMetrics || {};
        const grouped = new Map();
        dashboardSummary.rows.forEach(row => {
            const value = row.months?.[targetPeriod];
            if (!Number.isFinite(Number(value))) return;
            const key = JSON.stringify([row.activity, row.entity]);
            if (!grouped.has(key)) grouped.set(key, { activity: row.activity, entity: row.entity, cost: 0 });
            grouped.get(key).cost += Number(value);
        });
        const activityTotals = new Map();
        grouped.forEach(item => {
            const existing = entities.get(JSON.stringify([item.activity, item.entity])) || { activity: item.activity, entity: item.entity, qty: 0, hasQty: false };
            existing.cost = item.cost; existing.hasCost = true;
            if (!Number.isFinite(existing.qty) || existing.qty <= 0) { existing.qty = 0; existing.hasQty = false; }
            entities.set(JSON.stringify([item.activity, item.entity]), existing);
            const total = activityTotals.get(item.activity) || { cost: 0, entities: 0 };
            total.cost += item.cost; total.entities += 1; activityTotals.set(item.activity, total);
        });
        activityTotals.forEach((total, name) => {
            const existing = activities.get(name) || { activity: name, qty: 0, hasQty: false };
            existing.cost = total.cost; existing.hasCost = true;
            let volume = Number(metrics[volumeKeys[name]]?.value);
            if (name === 'OB Removal') { const blasting = Number(metrics.blasting?.value), freedig = Number(metrics.freedig?.value); volume = Number.isFinite(blasting) && Number.isFinite(freedig) ? blasting + freedig : NaN; }
            existing.qty = Number.isFinite(volume) ? volume : 0; existing.hasQty = Number.isFinite(volume); activities.set(name, existing);
            if (total.entities === 1 && existing.hasQty) entities.forEach(item => { if (item.activity === name && !item.hasQty) { item.qty = volume; item.hasQty = true; } });
        });
    };
    applyDashboardSource(period, current, counterpartCurrent);
    applyDashboardSource(previousPeriod, previous, counterpartPrevious);
    const dashboardMovementRows = [...new Set([...current.keys(), ...previous.keys()])].map(key => {
        const item = current.get(key) || previous.get(key); const values = calc(current.get(key), previous.get(key));
        return `<tr><td>${esc(item.activity)}</td><td class="num">${formatCell(item.hasQty ? item.qty : null, false, true)}</td><td class="num">${formatCell(item.hasCost ? item.cost : null, false, true)}</td><td class="num">${formatCell(values[0])}</td><td class="num">${formatCell(values[1])}</td><td class="num ${values[2] > 0 ? 'negative' : values[2] < 0 ? 'positive' : ''}">${formatCell(values[2])}</td><td class="num ${values[3] > 0 ? 'negative' : values[3] < 0 ? 'positive' : ''}">${formatCell(values[3], true)}</td><td>Dashboard DB source</td></tr>`;
    });
    const dashboardCounterpartRows = [...new Set([...counterpartCurrent.keys(), ...counterpartPrevious.keys()])].map(key => {
        const item = counterpartCurrent.get(key) || counterpartPrevious.get(key); const values = calc(counterpartCurrent.get(key), counterpartPrevious.get(key));
        return `<tr><td>${esc(item.activity)}</td><td>${esc(item.entity)}</td><td class="num">${formatCell(values[0])}</td><td class="num">${formatCell(values[1])}</td><td class="num ${values[2] > 0 ? 'negative' : values[2] < 0 ? 'positive' : ''}">${formatCell(values[2])}</td><td class="num ${values[3] > 0 ? 'negative' : values[3] < 0 ? 'positive' : ''}">${formatCell(values[3], true)}</td><td>Dashboard DB source</td></tr>`;
    });
    const monthIndex = date.getUTCMonth(), previousIndex = previousDate.getUTCMonth();
    const cogpRows = cogpMonthly.map(row => {
        const currentValue = period >= '2026-10' && Number(row.months?.[monthIndex]) === 0 ? null : row.months?.[monthIndex];
        const previousValue = previousPeriod >= '2026-10' && Number(row.months?.[previousIndex]) === 0 ? null : row.months?.[previousIndex];
        const change = Number.isFinite(Number(currentValue)) && Number.isFinite(Number(previousValue)) ? Number(currentValue) - Number(previousValue) : null;
        const percent = change !== null && Number(previousValue) !== 0 ? change / Math.abs(Number(previousValue)) : null;
        return `<tr><td>${esc(row.label)}</td><td class="num">${formatCell(currentValue)}</td><td class="num">${formatCell(previousValue)}</td><td class="num ${change > 0 ? 'negative' : change < 0 ? 'positive' : ''}">${formatCell(change)}</td><td class="num ${percent > 0 ? 'negative' : percent < 0 ? 'positive' : ''}">${formatCell(percent, true)}</td></tr>`;
    });
    const useDashboardSource = period >= '2026-10' && Boolean(dashboardSummary?.rows?.length);
    const status = excel?.abcMonthly && excel?.cogpMonthly ? `${monthLabel(date)} vs ${monthLabel(previousDate)}. Activity-Based Cost and Counterpart rates use ${useDashboardSource ? 'DB Cost Summary ÷ dashboard production volume for October 2026, matching localhost 3000' : 'the abcMonthly spreadsheet snapshot'}. Red = increase, green = decrease.` : 'Monthly data unavailable. Use Sync Production Now to load the spreadsheet.';
    return `<section class="table-card cost-analysis-card monthly-performance"><div class="cost-analysis-heading"><div><span class="cost-eyebrow">MONTHLY PERFORMANCE</span><h3>Monthly Cost Comparison</h3><p>Compare activity costs with the previous month.</p></div><label class="cost-period-control">Comparison month <input type="month" value="${esc(period)}" disabled></label></div><p id="excel-example-status">${esc(status)}</p><h4 class="cost-subheading">COGP Comparison <span>Production cost per metric ton</span></h4>${table(['Location', 'Current USD/MT', 'Previous USD/MT', 'Change USD/MT', 'Change %'], cogpRows)}<h4 class="cost-subheading">Activity-Based Cost <span>Volume, cost and monthly movement</span></h4>${table(['Activity', 'Qty', 'Cost USD', 'Current USD/Unit', 'Previous USD/Unit', 'Change USD/Unit', 'Change %', 'Remarks'], useDashboardSource ? dashboardMovementRows : movementRows)}<h4 class="cost-subheading">Activity-Based Cost by Counterpart <span>Breakdown by entity</span></h4>${table(['Activity', 'Counterpart', 'Current USD/Unit', 'Previous USD/Unit', 'Change USD/Unit', 'Change %', 'Remarks'], useDashboardSource ? dashboardCounterpartRows : counterpartRows)}</section>`;
}

async function inventory(period, mode) {
    const data = await api(`/api/inventory-valuation?period=${period}&mode=${mode}`);
    const stages = data.stages || [];
    const number = value => value == null || !Number.isFinite(Number(value)) ? '—' : reportAmount(value);
    const volume = value => value == null || !Number.isFinite(Number(value)) ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 0 });
    const usesWorkbookCosts = data.costMapping?.source === 'workbook';
    const hasHaulingRoutes = ['haulingBUAToWPUSD', 'haulingBUAToPortUSD', 'haulingWPToPortUSD'].every(key => Number.isFinite(Number(data.costMapping?.[key])));
    const warnings = [];
    if (data.quantitySource === 'dummy') warnings.push('SIMULATION — other stock movements use dummy data.');
    if ((data.coalMinedSheetPeriods || []).length) warnings.push(`Coal mined uses Google Sheets for: ${data.coalMinedSheetPeriods.join(', ')}. Other movements follow their indicated sources.`);
    if (data.quantitySource === 'workbook') warnings.push('This month’s quantity and adjustments use the supplied Excel history.');
    if ((data.historicalCostPeriods || []).length) warnings.push(`Costs use temporary Excel history: ${data.historicalCostPeriods.join(', ')}. Uploading GL for a month replaces that month’s costs.`);
    if ((data.missingCostPeriods || []).length) warnings.push(`Cost not yet uploaded: ${data.missingCostPeriods.join(', ')}. Provisional values will be recalculated after upload.`);
    if ((data.missingQuantityPeriods || []).length) warnings.push(`Tonnage unavailable: ${data.missingQuantityPeriods.join(', ')}.`);
    if ((data.unallocatedHauling || []).length) warnings.push(`Hauling allocation incomplete: ${data.unallocatedHauling.map(item => item.period).join(', ')}.`);
    warnings.push(...(data.historyIssues || data.issues || []).slice(0, 5));
    const renderLocationCard = stage => `<article class="inventory-location-card"><span class="inventory-location-badge">${esc(mode.toUpperCase())} · ${esc(data.periodKey || period)}</span><h3>${esc(stage.name)}</h3><div class="inventory-location-lines"><div><span>Opening balance</span><span>${volume(stage.beginningQtyMT)} MT · USD ${number(stage.beginningValueUSD)}</span></div><div><span>Inflow</span><strong>${volume(stage.inflowQtyMT)} MT</strong></div><div><span>Inflow value</span><span>USD ${number(stage.receiptValueUSD)}</span></div><div><span>Add cost</span><span>USD ${number(stage.addedCostUSD)}</span></div><div><span>Outflow</span><span>${volume(stage.outflowQtyMT)} MT</span></div><div><span>Adjustments</span><span>${volume(stage.adjustmentQtyMT)} MT · USD ${number(stage.adjustmentValueUSD)}</span></div><div class="inventory-key-metric"><span>Closing quantity</span><strong>${volume(stage.endingQtyMT)} MT</strong></div><div><span>Closing rate</span><strong>USD ${number(stage.endingRateUSD)} / MT</strong></div></div><div class="inventory-location-total"><span>Inventory value</span><strong>USD ${number(stage.endingValueUSD)}</strong></div></article>`;
    const washingIds = ['nonWash', 'washed', 'highAsh'];
    const washingNames = { nonWash: 'Non Wash', washed: 'Wash', highAsh: 'Reject (High Ash)' };
    const washing = washingIds.map(id => stages.find(stage => stage.id === id)).filter(Boolean);
    const washingCard = `<details class="inventory-washing-card"><summary><span class="inventory-location-badge">${esc(mode.toUpperCase())} · ${esc(data.periodKey || period)}</span><h3>Washing Plant</h3><p>Select to view location details</p><div class="inventory-washing-summary">${washing.map(stage => `<div><span>${washingNames[stage.id]}</span><strong>${volume(stage.endingQtyMT)} MT</strong><small>USD ${number(stage.endingValueUSD)} · USD ${number(stage.endingRateUSD)}/MT</small></div>`).join('')}</div><div class="inventory-location-total"><span>Total stock</span><strong>${volume(washing.reduce((sum, stage) => sum + Number(stage.endingQtyMT || 0), 0))} MT</strong></div></summary><div class="inventory-washing-details">${washing.map(renderLocationCard).join('')}</div></details>`;
    const locationCards = stages.map(stage => stage.id === 'nonWash' ? washingCard : washingIds.includes(stage.id) ? '' : renderLocationCard(stage)).join('');
    const totalQty = Number(data.totalEndingQtyMT ?? stages.reduce((total, stage) => total + Number(stage.endingQtyMT || 0), 0));
    const totalValue = Number(data.totalEndingValueUSD ?? stages.reduce((total, stage) => total + Number(stage.endingValueUSD || 0), 0));
    const colors = ['#2563eb', '#0891b2', '#7c3aed', '#eab308', '#0f766e', '#f97316', '#64748b', '#b45309'];
    const includedStages = stages.filter(stage => stage.includedInQuantityTotal !== false);
    const totalIncludedQty = includedStages.reduce((sum, stage) => sum + Number(stage.endingQtyMT || 0), 0);
    const chartCanDraw = totalIncludedQty > 0 && includedStages.every(stage => Number(stage.endingQtyMT) >= 0);
    const share = value => !chartCanDraw ? '—' : Number(value) / totalIncludedQty * 100 < 0.5 ? '<1%' : `${(Number(value) / totalIncludedQty * 100).toLocaleString('en-US', { maximumFractionDigits: 0 })}%`;
    let offset = 0;
    const circumference = 2 * Math.PI * 108;
    const segments = includedStages.filter(stage => Number(stage.endingQtyMT) > 0 && totalIncludedQty > 0).map((stage, index) => {
        const length = Number(stage.endingQtyMT) / totalIncludedQty * circumference;
        const segment = `<circle class="inventory-donut-segment" cx="140" cy="140" r="108" fill="none" stroke="${colors[index % colors.length]}" stroke-width="32" stroke-dasharray="${length} ${circumference - length}" stroke-dashoffset="${-offset}" transform="rotate(-90 140 140)"><title>${esc(stage.name)} · ${volume(stage.endingQtyMT)} MT · ${share(stage.endingQtyMT)} · USD ${number(stage.endingValueUSD)}</title></circle>`;
        offset += length;
        return segment;
    }).join('');
    const donut = `<div class="inventory-donut-layout"><svg viewBox="0 0 280 280" class="inventory-donut" role="img" aria-label="Coal stock composition ${esc(period)}"><circle cx="140" cy="140" r="108" fill="none" stroke="#e2e8f0" stroke-width="32"/>${segments}<text x="140" y="123" text-anchor="middle" fill="#64748b" font-size="12">Total volume</text><text x="140" y="153" text-anchor="middle" fill="#0f172a" font-size="22" font-weight="700">${volume(totalIncludedQty)}</text><text x="140" y="174" text-anchor="middle" fill="#64748b" font-size="12">MT</text></svg><div class="inventory-chart-legend"><div class="inventory-chart-legend-row inventory-chart-header"><span></span><span>Location</span><span>USD/MT</span><span>Volume</span><span>Share</span></div>${stages.map((stage, index) => `<div class="inventory-chart-legend-row"><i style="background:${colors[index % colors.length]}" aria-hidden="true"></i><span>${esc(stage.name)}</span><strong>USD ${number(stage.endingRateUSD)}</strong><span>${volume(stage.endingQtyMT)} MT</span><span class="inventory-chart-percent">${stage.includedInQuantityTotal === false ? 'Excluded' : share(stage.endingQtyMT)}</span></div>`).join('')}</div></div>`;
    const ledgerRows = stages.map(stage => `<tr><td><strong>${esc(stage.name)}</strong>${stage.includedInTotal === false ? '<br><small>Quantity excluded from total; USD value included</small>' : ''}</td>${['beginningQtyMT', 'beginningValueUSD', 'inflowQtyMT', 'receiptValueUSD', 'addedCostUSD', 'movingAverageRateUSD', 'outflowQtyMT', 'outflowValueUSD', 'adjustmentQtyMT', 'adjustmentValueUSD', 'endingQtyMT', 'endingValueUSD', 'endingRateUSD'].map(key => `<td class="num">${key.endsWith('MT') ? volume(stage[key]) : number(stage[key])}</td>`).join('')}</tr>`).join('');
    const buaRows = (data.buaHistory || []).map(stage => { const mined = (stage.rows || []).find(row => row.source === 'COGP BUA') || {}; return `<tr><td>${esc(stage.period)}</td><td class="num">${volume(stage.beginningQtyMT)}</td><td class="num">${number(stage.beginningValueUSD)}</td><td class="num">${volume(mined.qtyMT)}</td><td class="num">${number(mined.valueUSD)}</td><td class="num">${number(stage.adjustmentValueUSD)}</td><td class="num">${number(stage.movingAverageRateUSD)}</td><td class="num">${volume(stage.endingQtyMT)}</td><td class="num">${number(stage.endingValueUSD)}</td><td class="num">${number(stage.endingRateUSD)}</td><td>${esc(stage.costSource === 'workbook' ? 'Temporary Excel history' : stage.costSource === 'gl' ? 'GL' : 'Awaiting upload')}</td></tr>`; }).join('');
    const flowCards = stages.map(stage => `<details class="stage-card"><summary><strong>${esc(stage.name)}</strong><p>${volume(stage.endingQtyMT)} · USD ${number(stage.endingValueUSD)}</p></summary><div class="table-responsive"><table class="table-accounting"><thead><tr><th>Movement</th><th>Qty MT</th><th>Value USD</th><th>USD/MT</th></tr></thead><tbody>${(stage.rows || []).map(row => `<tr class="${['opening', 'available', 'closing'].includes(row.type) ? 'parent-row' : ''}"><td>${esc(row.label)}</td><td class="num">${volume(row.qtyMT)}</td><td class="num">${number(row.valueUSD)}</td><td class="num">${number(row.rateUSD)}</td></tr>`).join('')}</tbody></table></div></details>`).join('');
    const historyRows = (data.history || []).map(item => `<tr><td>${esc(item.period)}</td><td class="num">${volume(item.totalEndingQtyMT)}</td><td class="num">${number(item.totalEndingValueUSD)}</td><td class="num">${number(item.cogsShippedValUSD)}</td><td>${item.costSource === 'workbook' ? 'Temporary Excel history' : item.costSource === 'gl' || item.hasCostData ? 'GL' : 'Awaiting upload'}</td></tr>`).join('');
    const haulingStatus = hasHaulingRoutes ? `BUA to WP: USD ${number(data.costMapping.haulingBUAToWPUSD)} · BUA to Port: USD ${number(data.costMapping.haulingBUAToPortUSD)} · WP to Port: USD ${number(data.costMapping.haulingWPToPortUSD)}.` : usesWorkbookCosts ? `Excel history: Add Cost Non Wash USD ${number(data.costMapping.haulingToWPUSD)} · Add Cost BP PORT USD ${number(data.costMapping.portAddedCostUSD)} (combined value in Excel).` : 'The three routes are unavailable on the current server.';
    $('#pageContent').innerHTML = `
        ${sectionHeader('Monthly Inventory Valuation', 'Eight locations • Qty MT • Value USD • Monthly weighted average per stock.', `<label class="inventory-period-control">Period <input id="inventoryPeriod" type="month" value="${period}" aria-label="Inventory valuation period"></label>${unavailable('First Period Opening Balance')}`)}
        <p class="inventory-notice">${mode === 'ytd' ? `YTD movements: ${data.rangeStart || period} through ${data.periodKey || period}. Closing balances and composition are as of ${data.periodKey || period}; transfer rate is weighted by total outflow.` : `MTD movements and closing balances for ${data.periodKey || period}.`}</p>
        <p class="inventory-notice">${warnings.join(' ') || 'Cost and tonnage data are available; all values are in USD.'}</p>
        <p class="source-row">${badge('inventory_model.json')} ${badge('inventory_movements.json')} ${badge('inventory_movements_dummy.json')} ${badge('inventory_historical_costs.json')} ${badge('gl_data.json')}</p>
        <div class="inventory-summary"><article class="inventory-total-highlight"><div class="card-title">Total Stock Coal — ${esc(data.periodKey || period)}</div><strong>${volume(totalQty)} MT</strong><p>USD ${number(totalValue)}</p><small>${data.isProvisional ? 'Provisional value — see quantity and cost sources' : 'Includes all stock locations'}</small></article></div>
        ${card(`Coal Stock Composition <span class="subtle">— ${esc(data.periodKey || period)}</span>`, `<p class="inventory-chart-caption">Slices represent closing stock volume (MT) by location.</p>${donut}`)}
        <div class="inventory-location-grid">${locationCards}</div>
        <section class="table-card inventory-hauling-card"><div class="card-title">COGP Coal Hauling Routes — from Costing to</div><p>${esc(haulingStatus)}</p><p class="inventory-help">Add Cost Non Wash = Coal Hauling BUA to WP. Add Cost BP PORT = Coal Hauling BUA to Port + Coal Hauling WP to Port + COGP BP PORT. The three routes are read from Costing to in the GL.</p></section>
        ${card('Inventory Balances and Movements', table(['Stockpile', 'Opening MT', 'Opening USD', 'Inflow MT', 'Inflow Value USD', 'Add Cost USD', 'Rate Transfer USD/MT', 'Outflow MT', 'Outflow USD', 'Adj MT', 'Adj USD', 'Closing MT', 'Closing USD', 'Rate Closing USD/MT'], ledgerRows))}
        <details class="stage-card inventory-bua-details"><summary><strong>Mining BUA Monthly History</strong><p>Opening, coal mined, adjustments, transfer rate, and closing.</p></summary>${table(['Month', 'Opening MT', 'Opening USD', 'Coal mined MT', 'Coal mined USD', 'Adj USD', 'Rate Transfer USD/MT', 'Closing MT', 'Closing USD', 'Rate Closing USD/MT', 'Cost Source'], buaRows)}</details>
        <div class="stockpile-flow">${flowCards}</div>
        ${card('Closing Balance History — rolled forward from the first period', table(['Month', 'Total Stock MT', 'Stock Value USD', 'COGS USD', 'Cost Data'], historyRows))}`;
    $('#inventoryPeriod').addEventListener('change', event => { $('#period').value = event.target.value; render(); });
}

async function financial(period, mode) {
    const data = await api(`/api/financial-statements?period=${period}&mode=${mode}`);
    const report = data.report || {};
    const periods = report.periods || Object.keys(report.profitLoss?.[0]?.months || {});
    const formatStatementAmount = value => value == null ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 0, minimumFractionDigits: 0 }).replace(/^-/, '(') + (Number(value) < 0 ? ')' : '');
    const reportTable = lines => {
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const headers = ['Description', `YTD ${String(report.comparativeYear).slice(-2)}`, `YTD ${String(report.year).slice(-2)}`, ...periods.map(month => `${monthNames[Number(month.slice(5)) - 1]}-${month.slice(2, 4)}`)];
        const rows = (lines || []).map(line => {
            if (line.kind === 'heading') return `<tr class="parent-row"><td colspan="${headers.length}">${esc(line.label || line.id)}</td></tr>`;
            const classes = [line.kind === 'total' ? 'financial-final' : ''].filter(Boolean).join(' ');
            const cells = [
                `<td>${esc(line.label || line.id)}</td>`,
                `<td class="num financial-ytd">${formatStatementAmount(line.comparative)}</td>`,
                `<td class="num financial-ytd">${formatStatementAmount(line.ytd)}</td>`,
                ...periods.map(month => `<td class="num ${month === period ? 'selected-period' : ''}">${formatStatementAmount(line.months?.[month])}</td>`),
            ];
            return `<tr class="${classes}">${cells.join('')}</tr>`;
        });
        const header = `<tr><th>Description</th><th class="financial-ytd">YTD ${String(report.comparativeYear).slice(-2)}</th><th class="financial-ytd ${mode === 'ytd' ? 'selected-period' : ''}">YTD ${String(report.year).slice(-2)}</th>${periods.map(month => `<th class="${mode === 'mtd' && month === period ? 'selected-period' : ''}">${monthNames[Number(month.slice(5)) - 1]}-${month.slice(2, 4)}${month >= report.glStartPeriod ? `<br><small>${report.glMonths?.[month]?.rows ? 'GL' : 'No GL'}</small>` : ''}</th>`).join('')}</tr>`;
        return `<div class="table-responsive"><table class="table-accounting financial-report-table"><thead>${header}</thead><tbody>${rows.length ? rows.join('') : `<tr><td colspan="${headers.length}">No data available.</td></tr>`}</tbody></table></div>`;
    };
    const diagnostics = data.diagnostics || {};
    const accountRows = (data.accountBalances || []).map(row => `<tr><td>${esc(row.code)}</td><td>${esc(row.name)}</td><td>${esc(row.mapping)}</td><td class="num">${formatStatementAmount(row.baseUSD)}</td><td class="num">${formatStatementAmount(row.glMovementUSD)}</td><td class="num">${formatStatementAmount(row.closingUSD)}</td></tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Financial Statements', 'Profit and loss and balance sheet — September baseline plus GL movements from October onward. Amounts in USD. Baseline through 2026-09. Monthly GL movements are added from 2026-10. Finance and FX follow the financial statement format.', `<label>Month <input type="month" value="${period}" disabled></label>`)}
        <p class="financial-notice">${mode === 'ytd' ? `YTD P&amp;L through ${esc(period)}; balance sheet is the closing position as of that month.` : `MTD P&amp;L for ${esc(period)}; balance sheet is the closing position as of that month.`}</p>
        <p class="financial-notice">${esc(period)} GL rows. Accounts requiring mapping: ${(diagnostics.unmappedAccounts || []).length}. Baseline difference: ${money(diagnostics.baselineDifferenceUSD ?? diagnostics.balanceDifferenceUSD)}.</p>
        <p class="source-row">${badge('financial_baseline.json')} ${badge('gl_data.json')}</p>
        ${card(`${esc(companyName)} — Profit & Loss Statement (USD)`, reportTable(report.profitLoss || data.sections?.profitLoss), 'financial-reference-card')}
        ${card(`${esc(companyName)} — Balance Sheet (USD)`, reportTable(report.balanceSheet || data.sections?.balanceSheet), 'financial-reference-card')}
        <details class="stage-card financial-accounts-card"><summary><strong>Balances by Account</strong><p>September balance, subsequent GL movements, and closing balance. Debit positive, credit negative.</p></summary>${table(['COA', 'Account Name', 'Balance Sheet Mapping', 'September Base Balance', 'GL Movement', 'Closing Balance'], accountRows)}</details>`;
}

async function integration() {
    const [data, production] = await Promise.all([api('/api/integration-status'), api(`/api/production-summary?period=${$('#period').value}&mode=mtd`)]);
    const connector = (name, detail, status, source, lines, button, statusClass) => `<article class="connector-card"><div><div class="connector-heading"><h3>${name}</h3>${badge(source)}</div><p>${detail}</p><div class="connector-details">${lines.map(line => `<div>${line}</div>`).join('')}</div>${button}</div><div><span class="status ${statusClass}">${esc(status)}</span></div></article>`;
    const display = value => value === undefined || value === null || value === '' ? '—' : esc(value);
    const recentRows = (production.recentTickets || []).map(ticket => `<tr><td>${display(ticket['Tgl Keluar'])} ${display(ticket['Jam Keluar'])}</td><td><strong>${display(ticket.Product)}</strong></td><td>${display(ticket['No Pol'])}</td><td>${display(ticket.Sopir)}</td><td>${display(ticket.WEIGHBRIDGE || ticket.source)}</td><td class="num">${ticket.BRUTO == null ? '—' : Number(ticket.BRUTO).toLocaleString('en-US', { maximumFractionDigits: 0 })}</td><td class="num">${ticket.TARA == null ? '—' : Number(ticket.TARA).toLocaleString('en-US', { maximumFractionDigits: 0 })}</td><td class="num">${ticket.source === 'Google Sheets' ? '—' : Number(ticket.NETTO || 0).toLocaleString('en-US', { maximumFractionDigits: 0 })}</td><td class="num val-text">${qty((Number(ticket.NETTO) || 0) / 1000)} MT</td><td>${display(ticket.Operator)}</td></tr>`).join('');
    $('#pageContent').innerHTML = `
        ${sectionHeader('Integration & Real-Time Data Sources', 'Microsoft Dynamics 365 and Google Sheets production sources.')}
        <div class="connector-grid">${connector('Microsoft Dynamics 365 OData Connector', 'Reads D365 connection settings from the Laravel environment configuration.', data.d365.status === 'configured' ? 'Configured' : 'Not configured', 'environment', [`<b>Environment URL:</b> <code>${esc(data.d365.environmentUrl || 'Not configured')}</code>`, `<b>Entity Target:</b> <code>${esc(data.d365.entityName || '—')}</code>`, `<b>Auto Sync:</b> <code>${data.d365.autoSync ? 'Enabled' : 'Disabled'}</code>`, `<b>Local Records:</b> ${qty(data.database.glEntries)} GL records`], '<button id="viewGlData" class="btn btn-primary" type="button">View GL Data</button>', data.d365.status === 'configured' ? 'online' : 'manual')}${connector('Google Sheets Production Connector', 'Reads monthly production activities from DB and daily Coal Getting Actual from Produksi.', 'Manual Sync', 'production_daily_sheets.json', [`<b>Source unit:</b> <code>MT</code>`, `<b>Source:</b> ${esc(data.googleSheets.mode)}`, `<b>Metrics:</b> ${qty(data.database.monthlyMetrics)} records`, `<b>Last Sync:</b> ${esc(production.sheetLastSyncedAt || 'Not synced')}`], unavailable('Sync Production Now'), 'manual')}</div>
        ${card('Accrual Input — Add to Report Cost <span class="status manual">Manual</span>', `<p class="integration-note">Accrual menambah amount ke <strong>Report Cost</strong> dan <strong>Cost/MT</strong> pada periode terpilih. Amount = Qty × Rate dalam USD penuh.</p><div class="accrual-form-grid"><label>Periode<input type="month" value="${esc($('#period').value)}" disabled></label><label>Akun COA (5–9)<select disabled><option>Select account</option></select></label><label>Qty (MT)<input type="number" value="1" disabled></label><label>Rate (USD/MT)<input type="number" disabled></label><label>Amount (USD)<input type="text" value="0" disabled></label><label>Kategori<select disabled><option>Select category</option></select></label><button class="btn btn-primary" type="button" disabled>Add Accrual</button></div><p class="integration-note">Entry form remains available only after authentication is enabled.</p><div class="table-responsive"><table class="table-accounting"><thead><tr><th>Periode</th><th>Akun / Item Description</th><th>Kategori</th><th>Qty (MT)</th><th>Rate (USD/MT)</th><th>Amount (USD)</th><th></th></tr></thead><tbody><tr><td colspan="7" class="center">Belum ada accrual.</td></tr></tbody></table></div>`)}
        ${card('Latest 15 Production Records', table(['Date & Time', 'Product', 'Vehicle Plate', 'Driver', 'Weighbridge', 'BRUTO (Kg)', 'TARA (Kg)', 'NETTO (Kg)', 'NETTO (MT)', 'Operator'], recentRows))}`;
    $('#viewGlData').addEventListener('click', () => viewGlData($('#period').value));
}

async function configuration() {
    const data = await api('/api/config');
    const label = key => key.replace(/([A-Z])/g, ' $1').replace(/^./, value => value.toUpperCase());
    const editable = {
        system: ['companyName', 'currency', 'currencyUnit', 'periodYear', 'periodMonth', 'amountScale'],
        d365Config: ['enabled', 'entityName', 'syncIntervalMinutes', 'autoSync'],
        sheetsConfig: ['enabled', 'spreadsheetId', 'sheetName', 'range', 'syncIntervalMinutes', 'autoSync', 'gid'],
        inventorySetup: ['valuationMethod'],
    };
    const sanitize = (value, key = '') => {
        if (/secret|password|token/i.test(key)) return 'Configured securely';
        if (Array.isArray(value)) return value.map(item => sanitize(item));
        if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([childKey, childValue]) => [childKey, sanitize(childValue, childKey)]));
        return value;
    };
    const display = (key, value) => {
        if (value === null || value === undefined || value === '') return '—';
        if (typeof value === 'object') return `<pre class="config-json">${esc(JSON.stringify(sanitize(value, key), null, 2))}</pre>`;
        if (/secret|password|token/i.test(key)) return '<span class="config-redacted">Configured securely</span>';
        if (typeof value === 'boolean') return value ? '<span class="status online">Enabled</span>' : '<span class="status manual">Disabled</span>';
        return esc(value);
    };
    const editor = (section, key, value) => {
        const inputType = typeof value === 'boolean' ? 'checkbox' : typeof value === 'number' ? 'number' : 'text';
        const inputValue = inputType === 'checkbox' ? (value ? 'checked' : '') : `value="${esc(value)}"`;
        return `<div class="config-editor"><input data-config-input="true" data-config-key="${esc(section)}" data-config-field="${esc(key)}" type="${inputType}" ${inputValue}></div>`;
    };
    const sections = Object.entries(data || {}).filter(([section]) => section !== 'd365Config').map(([section, values]) => {
        const rows = Object.entries(values && typeof values === 'object' && !Array.isArray(values) ? values : { value: values }).map(([key, value]) => {
            const canEdit = editable[section]?.includes(key) && (typeof value !== 'object' || value === null);
            return `<tr><th>${esc(label(key))}</th><td>${canEdit ? editor(section, key, value) : display(key, value)}</td></tr>`;
        }).join('');
        return `<section class="table-card config-card"><div class="card-title">${esc(label(section))}</div><div class="table-responsive"><table class="table-accounting config-table"><tbody>${rows || '<tr><td colspan="2">No configuration available.</td></tr>'}</tbody></table></div></section>`;
    }).join('');
    $('#pageContent').innerHTML = `${sectionHeader('Configuration', 'System, connector, inventory, and period settings used by the dashboard.', `<span class="config-source">Source: /api/config</span>`)}<p class="financial-notice">Editable fields are marked with an input. Sensitive credentials and nested setup details remain read-only.</p><div class="config-actions"><button id="configSaveAll" class="btn btn-primary" type="button">Save All Changes</button><p id="config-status" class="config-status" aria-live="polite"></p></div><div class="config-grid">${sections || '<div class="table-card error-card">No configuration available.</div>'}</div>`;
    $('#configSaveAll').addEventListener('click', async event => {
        const button = event.currentTarget;
        const inputs = [...document.querySelectorAll('[data-config-input]')];
        button.disabled = true;
        $('#config-status').textContent = 'Saving configuration…';
        try {
            for (const input of inputs) {
                const response = await fetch('/api/config', { method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ key: input.dataset.configKey, field: input.dataset.configField, value: input.type === 'checkbox' ? input.checked : input.value }) });
                const text = await response.text();
                let result = null;
                try { result = text ? JSON.parse(text) : null; } catch { result = null; }
                if (!response.ok) throw new Error(result?.message || `Request failed (${response.status})`);
            }
            $('#config-status').textContent = 'All configuration changes saved.';
            setTimeout(() => location.reload(), 700);
        } catch (error) {
            $('#config-status').textContent = `Could not save configuration: ${error.message}`;
            button.disabled = false;
        }
    });
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
        else if (state.view === 'config') await configuration();
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
