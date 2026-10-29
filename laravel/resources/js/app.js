import './bootstrap';

const $ = selector => document.querySelector(selector);
const money = value => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(Number(value || 0));
const qty = value => Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
const esc = value => String(value ?? '—').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[char]);
const state = { view: new URLSearchParams(location.search).get('view') || 'overview' };
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
        api(`/api/inventory-valuation?period=${period}`),
        api(`/api/production-summary?period=${period}`),
    ]);
    const metrics = Array.isArray(production) ? production : production.data || [];
    const productionMT = Number(metrics.find(item => item.metric_key === 'coalGetting')?.value || 0);
    const profitLoss = financial.sections?.profitLoss || [];
    const line = id => profitLoss.find(item => item.id === id)?.value || 0;
    const stages = inventory.stages || [];
    const inventoryValue = stages.reduce((total, stage) => total + Number(stage.endingValueUSD || 0), 0);
    const kpis = [
        ['TOTAL ALL COST (GL)', money(cost.grandTotal.allCost), 'Costing to allocation, including Direct Expense (USD)', 'blue'],
        ['TOTAL PRODUCTION VOLUME', `${qty(productionMT)} MT`, 'Coal mined / weighbridge volume', 'val'],
        ['AVERAGE COST / MT', productionMT ? `${money(Number(cost.grandTotal.allCost) / productionMT)} / MT` : '—', 'Total operating cost / net MT', 'cost'],
        ['TOTAL INVENTORY VALUE', money(inventoryValue), 'Includes all stock locations', 'success'],
        ['GROSS PROFIT', money(line('grossProfit')), 'Coal sales less cost of sales', 'blue'],
        ['OPERATING PROFIT / BEFORE INTEREST', money(line('profitBeforeInterest')), 'Operating profit for the period', 'blue'],
    ];
    const bars = metrics.slice(0, 8).map((item, index) => `<div class="bar-row"><span>${esc(item.label || item.metric_key)}</span><i style="width:${Math.max(8, 100 - index * 11)}%"></i><b>${qty(item.value)}</b></div>`).join('');
    $('#pageContent').innerHTML = `
        ${sectionHeader('Operations & Financial Overview', 'Microsoft Dynamics 365 General Ledger and Google Sheets weighbridge data')}
        <div class="kpi-grid">${kpis.map(([label, value, sub, type]) => `<article class="kpi-card ${type}"><span class="kpi-title">${label}</span><strong class="kpi-value">${value}</strong><small class="kpi-sub">${sub}</small></article>`).join('')}</div>
        ${card(`Production by Activity — Google Sheets DB ${badge('production_daily_sheets.json')}`, table(['Activity', 'Volume', 'Unit', 'Availability'], metrics.map(item => `<tr><td>${esc(item.label || item.metric_key)}</td><td class="num">${qty(item.value)}</td><td>${esc(item.unit)}</td><td><span class="status online">Available</span></td></tr>`)))}
        <div class="overview-lower"><section class="chart-card"><div class="card-title">Daily Production Volume (MT)</div><p class="chart-caption">Operational activity snapshot from the selected period.</p><div class="bars">${bars}</div></section><section class="chart-card"><div class="card-title">Data Sources</div><div class="source-list"><p><b>●</b> D365 General Ledger <span>Online</span></p><p><b>●</b> Google Sheets DB <span>Manual Sync</span></p><p><b>●</b> Frozen Baseline <span>Verified</span></p></div></section></div>`;
}

async function cost(period, mode) {
    const data = await api(`/api/cost-matrix?period=${period}&mode=${mode}`);
    const fields = [['costMining', 'Mining'], ['costHaulingBUAToWP', 'Haul BUA→WP'], ['costHaulingBUAToPort', 'Haul BUA→Port'], ['costHaulingWPToPort', 'Haul WP→Port'], ['costWP', 'Washing Plant'], ['costPort', 'BP PORT'], ['valMining', 'Mining'], ['valHauling', 'Coal Hauling'], ['valWP', 'Washing Plant'], ['valPort', 'BP PORT'], ['directGA', 'GA'], ['sellingExpense', 'Selling']];
    const rows = data.rows.map(row => `<tr class="${row.isParent ? 'parent-row' : ''}"><td>${esc(row.code)}</td><td>${esc(row.name)}</td><td class="center">${row.isParent ? 'Σ' : '✓'}</td><td class="num">${money(row.allCost)}</td>${fields.map(([field]) => `<td class="num">${money(row[field])}</td>`).join('')}</tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Report Cost: COGP & Direct Expense', 'Accounts beginning 7–9 are allocated to GA. Accounts beginning 5–6 follow Costing to; Direct Expense is allocated by Valuation to. Amounts in USD.', `<label>Month <input type="month" value="${period}" disabled></label><input class="input-search" placeholder="🔍 Search account code / name..." disabled>${unavailable('Show All Accounts')}${unavailable('Parent Accounts Only')}${unavailable('Hide Zero Balances')}${unavailable('📥 Export Excel/CSV')}`)}
        ${card(`${badge('gl_data.json')} ${badge('cost_matrix_definition.json')} ${badge('accruals.json')}<p class="table-note">${data.rows.length} accounts • ${mode.toUpperCase()} • ${period}</p>`, table(['Account Code', 'Account Name', 'Check', 'ALL Cost', ...fields.map(([, label]) => label)], rows, 'cost-report-table'))}`;
}

async function variance(period, mode) {
    const data = await api(`/api/cost-variance?period=${period}&mode=${mode}`);
    const rows = data.rows.filter(row => !row.isParent).map(row => `<tr><td>${esc(row.code)}</td><td>${esc(row.name)}</td><td class="num">${money(row.previous)}</td><td class="num">${money(row.current)}</td><td class="num ${row.change > 0 ? 'negative' : 'positive'}">${money(row.change)}</td><td class="num">${row.changePct == null ? '—' : `${Number(row.changePct).toFixed(2)}%`}</td><td><input class="remark-input" value="" placeholder="Remark unavailable" disabled></td></tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Cost Variance Analysis', 'Compare monthly cost by account and location; record explanations for each variance.')}
        ${card(`<div class="variance-controls"><label>Current month <input type="month" value="${period}" disabled></label><label>Location / category <select disabled><option>All categories</option></select></label><label>Search account <input type="search" placeholder="Account code or name" disabled></label><label class="variance-checkbox"><input type="checkbox" checked disabled> Hide zero balances</label></div><p class="table-note">${badge('gl_data.json')} ${badge('cost_variance_remarks.json')} Comparison ${data.previousPeriod} → ${data.period}</p>`, table(['Account', 'Account Name', 'Previous month', 'Current month', 'Variance USD', 'Variance %', 'Remark'], rows, 'variance-table'))}`;
}

async function metric(period, mode) {
    const data = await api(`/api/cost-per-mt?period=${period}&mode=${mode}`);
    const stageCards = data.stages.map(stage => `<article class="stage-card"><span>${esc(stage.stage)}</span><strong>${stage.costPerMT == null ? '—' : `${money(stage.costPerMT)} / MT`}</strong><small>${qty(stage.metricTons)} MT operating volume</small><em>${money(stage.totalCost)} total cost</em></article>`).join('');
    const rows = data.stages.map(stage => `<tr><td>${esc(stage.stage)}</td><td class="num">${money(stage.valuationCost)}</td><td class="num">${money(stage.operatingCost)}</td><td class="num">${money(stage.totalCost)}</td><td class="num">${qty(stage.metricTons)}</td><td class="num">${stage.costPerMT == null ? '—' : money(stage.costPerMT)}</td><td class="num">${stage.costPerTotalMT == null ? '—' : money(stage.costPerTotalMT)}</td></tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Cost per Metric Ton Analysis', 'D365 accounting costs and Google Sheets weighbridge volume')}
        <p class="source-row">${badge('gl_data.json')} ${badge('production_daily_sheets.json (DB)')}</p>
        <div class="cost-stage-grid">${stageCards}</div>
        ${card(`Coal Mining Cost by Stage ${badge('gl_data.json')} ${badge('production_daily_sheets.json')}`, table(['Mining Activity', 'From Valuation to', 'From Costing to', 'Total Cost', 'Operating Volume', 'Stage Cost / MT', 'Cost / MT Total Production'], rows))}
        ${card('Monthly Cost Comparison <span class="subtle">Previous-month comparison will be populated from historical activity data.</span>', `<div class="placeholder-analysis"><b>MONTHLY PERFORMANCE</b><p>Current overall cost: ${money(data.grandTotalCost)} • Overall cost per MT: ${data.overallCostPerMT == null ? '—' : money(data.overallCostPerMT)}</p></div>`, 'cost-analysis-card')}`;
}

async function inventory(period) {
    const data = await api(`/api/inventory-valuation?period=${period}`);
    const stages = data.stages || [];
    const totalQty = stages.reduce((total, stage) => total + Number(stage.endingQtyMT || 0), 0);
    const totalValue = stages.reduce((total, stage) => total + Number(stage.endingValueUSD || 0), 0);
    const cards = stages.map(stage => `<article class="inventory-stage"><h3>${esc(stage.name)}</h3><p><span>Closing Qty</span><b>${qty(stage.endingQtyMT)} MT</b></p><p><span>Closing Value</span><b>${money(stage.endingValueUSD)}</b></p><p><span>Moving Average</span><b>${money(stage.endingRateUSD)} / MT</b></p></article>`).join('');
    const rows = stages.map(stage => `<tr><td>${esc(stage.name)}</td><td class="num">${qty(stage.beginningQtyMT)}</td><td class="num">${money(stage.beginningValueUSD)}</td><td class="num">${qty(stage.inflowQtyMT)}</td><td class="num">${money(stage.inflowTotalValueUSD)}</td><td class="num">${money(stage.addedCostUSD)}</td><td class="num">${qty(stage.outflowQtyMT)}</td><td class="num">${money(stage.outflowValueUSD)}</td><td class="num">${qty(stage.endingQtyMT)}</td><td class="num">${money(stage.endingValueUSD)}</td><td class="num">${money(stage.endingRateUSD)}</td></tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Monthly Inventory Valuation', 'Eight locations • Qty MT • Value USD • Monthly weighted average per stock.', `<label>Month <input type="month" value="${period}" disabled></label>${unavailable('First Period Opening Balance')}`)}
        <p class="inventory-notice">Inventory valuation uses the frozen workbook movement order and the selected month closing balance.</p>
        <p class="source-row">${badge('inventory_model.json')} ${badge('inventory_movements.json')} ${badge('gl_data.json')}</p>
        <div class="inventory-summary"><article><span>Total Closing Stock</span><strong>${qty(totalQty)} MT</strong></article><article><span>Total Inventory Value</span><strong>${money(totalValue)}</strong></article><article><span>Average Cost / MT</span><strong>${totalQty ? money(totalValue / totalQty) : '—'}</strong></article></div>
        <div class="inventory-location-grid">${cards}</div>
        ${card(`Inventory Balances and Movements ${badge('inventory_model.json')} ${badge('gl_data.json')}`, table(['Stockpile', 'Opening MT', 'Opening USD', 'Inflow MT', 'Inflow Value', 'Add Cost', 'Outflow MT', 'Outflow USD', 'Closing MT', 'Closing USD', 'Rate Closing'], rows))}
        ${card('Coal Stock Composition', `<div class="inventory-composition">${stages.map(stage => `<p><i></i><span>${esc(stage.name)}</span><b>${qty(stage.endingQtyMT)} MT</b></p>`).join('')}</div>`)}`;
}

async function financial(period, mode) {
    const data = await api(`/api/financial-statements?period=${period}&mode=${mode}`);
    const statement = (title, lines) => card(title, table(['Description', 'Amount USD'], (lines || []).map(line => `<tr class="${line.kind === 'heading' ? 'statement-heading' : line.kind === 'total' ? 'statement-total' : ''}"><td>${esc(line.label || line.id)}</td><td class="num">${money(line.value)}</td></tr>`)), 'financial-card');
    $('#pageContent').innerHTML = `
        ${sectionHeader('Financial Statements', 'Profit & Loss, balance sheet, and financial baseline reconciliation.', `<label>Month <input type="month" value="${period}" disabled></label>`)}
        <p class="source-row">${badge('financial_baseline.json')} ${badge('gl_data.json')}</p>
        <div class="financial-grid">${statement('Profit & Loss Statement', data.sections?.profitLoss)}${statement('Balance Sheet', data.sections?.balanceSheet)}</div>
        ${card('Financial Statement Diagnostics', `<div class="diagnostic-grid"><p><span>Data source</span><b>${esc(data.dataSource)}</b></p><p><span>Period</span><b>${esc(data.period)} ${esc(data.mode).toUpperCase()}</b></p><p><span>Inventory reconciliation</span><b>Available</b></p></div>`)}`;
}

async function integration() {
    const data = await api('/api/integration-status');
    const connector = (name, detail, status, button) => `<article class="connector-card"><div><span class="connector-icon">🔄</span><h3>${name}</h3><p>${detail}</p></div><div><span class="status ${status === 'online' ? 'online' : 'manual'}">${esc(status)}</span>${button}</div></article>`;
    $('#pageContent').innerHTML = `
        ${sectionHeader('Integration & Real-Time Data Sources', 'Connection status and source freshness for local dashboard data.')}
        <div class="connector-grid">${connector('Microsoft Dynamics 365 General Ledger', data.d365.mode, data.d365.status, unavailable('View GL Data'))}${connector('Google Sheets Production Connector', data.googleSheets.mode, data.googleSheets.status, unavailable('Sync Production Now'))}</div>
        ${card('Accrual Input — Add to Report Cost', `<p class="integration-note">Accrual adds amount to Report Cost and Cost/MT for the selected period. Entry form remains available only after authentication is enabled.</p>${unavailable('Add Accrual')}`)}
        ${card('Data Source Summary', table(['Source', 'Status', 'Detail'], [`<tr><td>D365 GL</td><td><span class="status online">${esc(data.d365.status)}</span></td><td>${esc(data.d365.mode)} • ${qty(data.database.glEntries)} GL records</td></tr>`, `<tr><td>Google Sheets</td><td><span class="status manual">${esc(data.googleSheets.status)}</span></td><td>${esc(data.googleSheets.mode)} • ${qty(data.database.monthlyMetrics)} metrics</td></tr>`, `<tr><td>Frozen Baseline</td><td><span class="status verified">verified</span></td><td>Data freeze ${esc(data.dataFreeze)}</td></tr>`]))}`;
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
        else if (state.view === 'inventory') await inventory(period);
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
