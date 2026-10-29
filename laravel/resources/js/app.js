import './bootstrap';

const money = value => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(Number(value || 0));
const number = value => Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
const $ = selector => document.querySelector(selector);
const escapeHtml = value => String(value ?? '—').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[char]);

const state = { view: new URLSearchParams(location.search).get('view') || 'overview' };
const api = async (path) => {
    const response = await fetch(path);
    if (!response.ok) throw new Error(`Request failed: ${response.status}`);
    return (await response.json()).data;
};

function setTable(headers, rows) {
    $('#tableHead').innerHTML = `<tr>${headers.map(header => `<th>${escapeHtml(header)}</th>`).join('')}</tr>`;
    $('#tableBody').innerHTML = rows.length ? rows.join('') : `<tr><td colspan="${headers.length}">No data available.</td></tr>`;
}

function setCopy(title, subtitle, panelTitle, notice) {
    $('.heading h1').textContent = title;
    $('.heading p').textContent = subtitle;
    $('.panel-title h2').textContent = panelTitle;
    $('.notice').textContent = notice;
}

async function loadOverview(period, mode) {
    const [cost, financial, inventory, production] = await Promise.all([
        api(`/api/cost-matrix?period=${period}&mode=${mode}`),
        api(`/api/financial-statements?period=${period}&mode=${mode}`),
        api(`/api/inventory-valuation?period=${period}`),
        api(`/api/production-summary?period=${period}`),
    ]);
    const metrics = Array.isArray(production) ? production : production.data || [];
    const coal = Number(metrics.find(item => item.metric_key === 'coalGetting')?.value || 0);
    const closing = inventory.stages?.reduce((total, stage) => {
        const row = [...(stage.rows || [])].reverse().find(item => item.type === 'closing');
        return total + Number(row?.valueUSD || 0);
    }, 0) || 0;
    const profitLoss = financial.sections?.profitLoss || [];
    const valueOf = id => profitLoss.find(item => item.id === id)?.value || 0;
    $('#allCost').textContent = money(cost.grandTotal.allCost);
    $('#production').textContent = `${number(coal)} MT`;
    $('#costPerMt').textContent = coal ? `${money(Number(cost.grandTotal.totalExpense) / coal)} / MT` : 'USD —';
    $('#inventory').textContent = money(closing);
    $('#grossProfit').textContent = money(valueOf('grossProfit'));
    $('#expense').textContent = `(${money(valueOf('profitBeforeInterest'))})`;
    setCopy('Operations & Financial Overview', 'Microsoft Dynamics 365 General Ledger and Google Sheets weighbridge data', 'Production by Activity — Google Sheets DB', 'Monthly figures use the DB sheet. Blank source cells are unavailable; YTD sums available months only.');
    $('.panel-title span').textContent = '📁 production_daily_sheets.json';
    setTable(['Activity', 'Volume', 'Unit', 'Availability'], metrics.map(item => `<tr><td>${escapeHtml(item.label || item.metric_key)}</td><td>${item.value == null ? '—' : number(item.value)}</td><td>${escapeHtml(item.unit)}</td><td>Available</td></tr>`));
}

async function loadView(view, period, mode) {
    state.view = view;
    document.querySelectorAll('.tabs a').forEach(tab => tab.classList.toggle('active', tab.dataset.view === view));
    $('#periodText').textContent = period;
    $('#viewText').textContent = `${mode.toUpperCase()} — ${period}`;
    history.replaceState(null, '', `/?view=${view}&period=${period}&mode=${mode}`);
    if (view === 'overview') return loadOverview(period, mode);
    if (view === 'cost') {
        const data = await api(`/api/cost-matrix?period=${period}&mode=${mode}`);
        setCopy('Report Cost: COGP & Direct Expense', 'Accounts, costing allocation, valuation, and direct expense in USD.', 'Cost Matrix', 'Cost allocation by account and period. Parent accounts are excluded from the detail rows.');
        $('.panel-title span').textContent = `${data.dataSource} · ${data.period}`;
        return setTable(['Code', 'Account', 'All Cost', 'COGP', 'Expense'], data.rows.filter(row => !row.isParent).map(row => `<tr><td>${escapeHtml(row.code)}</td><td>${escapeHtml(row.name)}</td><td>${money(row.allCost)}</td><td>${money(row.totalCOGP)}</td><td>${money(row.totalExpense)}</td></tr>`));
    }
    if (view === 'variance') {
        const data = await api(`/api/cost-variance?period=${period}&mode=${mode}`);
        setCopy('Cost Variance Analysis', 'Current period versus previous period by account and cost category.', 'Variance Analysis', `Comparison ${data.period} versus ${data.previousPeriod}. Positive change increases cost.`);
        $('.panel-title span').textContent = '📁 cost_report_history.json';
        return setTable(['Code', 'Account', 'Previous', 'Current', 'Change', 'Change %'], data.rows.filter(row => !row.isParent).map(row => `<tr><td>${escapeHtml(row.code)}</td><td>${escapeHtml(row.name)}</td><td>${money(row.previous)}</td><td>${money(row.current)}</td><td class="${row.change > 0 ? 'negative' : 'positive'}">${money(row.change)}</td><td>${row.changePct == null ? '—' : `${Number(row.changePct).toFixed(2)}%`}</td></tr>`));
    }
    if (view === 'metric') {
        const data = await api(`/api/cost-per-mt?period=${period}&mode=${mode}`);
        setCopy('Cost per Metric Ton', 'Stage cost per MT using production volume and cost allocation.', 'Cost per Metric Ton', 'Each stage is calculated from the selected cost matrix and production metric.');
        $('.panel-title span').textContent = '📁 gl_data.json · production_daily_sheets.json';
        return setTable(['Stage', 'Metric Tons', 'Total Cost', 'Cost / MT'], (data.stages || []).map(row => `<tr><td>${escapeHtml(row.stage)}</td><td>${number(row.metricTons)}</td><td>${money(row.totalCost)}</td><td>${row.costPerMT == null ? '—' : `${money(row.costPerMT)} / MT`}</td></tr>`));
    }
    if (view === 'inventory') {
        const data = await api(`/api/inventory-valuation?period=${period}`);
        setCopy('Inventory Valuation (Moving Avg)', 'Closing stock, movement ledger, and weighted average cost by stage.', 'Inventory Valuation Ledger', 'Inventory valuation follows the frozen workbook movement order.');
        $('.panel-title span').textContent = '📁 inventory_movements.json';
        return setTable(['Stage', 'Closing Qty', 'Closing Value', 'Rate / MT'], (data.stages || []).map(stage => { const row = [...(stage.rows || [])].reverse().find(item => item.type === 'closing') || {}; return `<tr><td>${escapeHtml(stage.name)}</td><td>${number(row.qtyMT)} MT</td><td>${money(row.valueUSD)}</td><td>${row.rateUSD == null ? '—' : `${money(row.rateUSD)} / MT`}</td></tr>`; }));
    }
    if (view === 'financial') {
        const data = await api(`/api/financial-statements?period=${period}&mode=${mode}`);
        setCopy('Financial Statements (FS)', 'Profit & Loss, balance sheet, and financial baseline reconciliation.', 'Financial Statement Summary', 'Profit & Loss and balance sheet lines for the selected period and mode.');
        $('.panel-title span').textContent = '📁 financial_baseline.json';
        const rows = [...(data.sections?.profitLoss || []), ...(data.sections?.balanceSheet || [])];
        return setTable(['Line', 'Value', 'Type', 'Availability'], rows.map(row => `<tr><td>${escapeHtml(row.label || row.id)}</td><td>${money(row.value)}</td><td>${escapeHtml(row.kind || 'detail')}</td><td>Available</td></tr>`));
    }
    const data = await api('/api/integration-status');
    setCopy('Integration & Data Sources', 'Connection status and source freshness for GL and Google Sheets.', 'Integration Status', 'Current source health and local snapshot record counts.');
    $('.panel-title span').textContent = `Data freeze ${data.dataFreeze}`;
    return setTable(['Source', 'Status', 'Mode / Records', 'Availability'], [
        `<tr><td>D365 GL</td><td>${escapeHtml(data.d365.status)}</td><td>${escapeHtml(data.d365.mode)}</td><td>Available</td></tr>`,
        `<tr><td>Google Sheets</td><td>${escapeHtml(data.googleSheets.status)}</td><td>${escapeHtml(data.googleSheets.mode)}</td><td>Available</td></tr>`,
        `<tr><td>Database</td><td>Online</td><td>${number(data.database.glEntries)} GL / ${number(data.database.monthlyMetrics)} metrics</td><td>Verified</td></tr>`,
    ]);
}

async function refresh() {
    try { await loadView(state.view, $('#period').value, $('#mode').value); }
    catch (error) { console.error(error); setTable(['Status'], [`<tr><td>Unable to load dashboard data: ${escapeHtml(error.message)}</td></tr>`]); }
}

document.querySelectorAll('.tabs a').forEach(tab => tab.addEventListener('click', event => { event.preventDefault(); state.view = tab.dataset.view; refresh(); }));
$('#period').addEventListener('change', refresh);
$('#mode').addEventListener('change', refresh);
refresh();
