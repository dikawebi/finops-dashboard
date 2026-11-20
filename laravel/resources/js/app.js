import './bootstrap';
import { mountProductionMap } from './production-map';

const $ = selector => document.querySelector(selector);
const signedNumber = (value, maximumFractionDigits = 2, minimumFractionDigits = 0, suffix = '') => {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '—';
    const formatted = Math.abs(amount).toLocaleString('en-US', { maximumFractionDigits, minimumFractionDigits });
    return amount < 0 ? `(${formatted}${suffix})` : `${formatted}${suffix}`;
};
const money = value => `USD ${signedNumber(Number(value || 0), 0)}`;
const reportAmount = value => { const amount = Number(value || 0); return Math.abs(amount) < 0.000001 ? '-' : signedNumber(amount, 0); };
const detailMoney = value => signedNumber(value, 3, 3);
const qty = value => signedNumber(Number(value || 0), 2);
const formatNegativeNumbers = root => {
    if (!root) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    const negativePattern = /(?:USD|IDR)\s*\([\d,]+(?:\.\d+)?%?\)|\([\d,]+(?:\.\d+)?%?\)/g;
    nodes.forEach(node => {
        if (!node.nodeValue || node.parentElement?.closest('input,textarea,select,button,svg,script,style,.negative-number')) return;
        const matches = [...node.nodeValue.matchAll(negativePattern)];
        if (!matches.length) return;
        const fragment = document.createDocumentFragment();
        let cursor = 0;
        matches.forEach(match => {
            fragment.append(document.createTextNode(node.nodeValue.slice(cursor, match.index)));
            const value = document.createElement('span');
            value.className = 'negative-number';
            value.textContent = match[0];
            fragment.append(value);
            cursor = match.index + match[0].length;
        });
        fragment.append(document.createTextNode(node.nodeValue.slice(cursor)));
        node.replaceWith(fragment);
    });
};
const negativeNumbersObserver = new MutationObserver(() => formatNegativeNumbers($('#pageContent')));
const negativeNumbersRoot = $('#pageContent');
if (negativeNumbersRoot) {
    formatNegativeNumbers(negativeNumbersRoot);
    negativeNumbersObserver.observe(negativeNumbersRoot, { childList: true, characterData: true, subtree: true });
}
const currentLocale = () => document.documentElement.lang === 'en' ? 'en-US' : 'id-ID';
const formatDate = value => { const match = String(value ?? '').slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/); if (!match) return value ?? '—'; return new Intl.DateTimeFormat(currentLocale(), { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])))); };
const formatSyncTimestamp = value => { if (!value) return document.documentElement.lang === 'en' ? 'Not synced' : 'Belum disinkronkan'; const date = new Date(value); if (Number.isNaN(date.getTime())) return value; const locale = document.documentElement.lang === 'en' ? 'en-GB' : 'id-ID'; return `${new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'long', year: 'numeric', timeZone: 'Asia/Bangkok' }).format(date)}, ${new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'Asia/Bangkok' }).format(date)} UTC+7`; };
const formatPeriod = value => { const match = String(value ?? '').match(/^(\d{4})-(\d{2})$/); if (!match) return value || '—'; return new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1)).toLocaleDateString(currentLocale(), { month: 'long', year: 'numeric', timeZone: 'UTC' }); };
const setGlReviewLoading = visible => { let overlay = document.querySelector('#glReviewLoading'); if (visible && !overlay) { overlay = document.createElement('div'); overlay.id = 'glReviewLoading'; overlay.className = 'gl-review-loading'; overlay.innerHTML = '<div class="gl-review-loading-card"><i></i><strong>Loading GL transactions</strong><span>Preparing all rows. Please wait…</span></div>'; document.body.appendChild(overlay); } if (!visible) overlay?.remove(); };
const esc = value => String(value ?? '—').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[char]);
const companyName = document.body.dataset.companyName || 'FinOps Dashboard';
const urlState = new URLSearchParams(location.search);
const state = { view: urlState.get('view') || 'overview', costSearch: '', costParentOnly: false, costHideZero: false, costCollapsed: {}, accountHierarchyCollapsed: {}, voucherSummaryCollapsed: false, glReview: { account: '', search: '', dateFrom: '', dateTo: '', rowsPerPage: 100, mappingStatus: 'all', valuationTo: '', costingTo: '', headerFilters: { account: '', account_name: '', voucher: '', journal_number: '', description: '', costing_to: '', valuation_to: '', currency: '' } }, varianceSearch: '', varianceHideZero: true, varianceMinimum: 0, varianceOrder: 'account', varianceLocation: 'allCost' };
let pageAccess = {};
try { pageAccess = JSON.parse(document.body.dataset.pageAccess || '{}'); } catch { pageAccess = {}; }
const administratorAccess = document.body.dataset.isAdministrator === 'true';
const canEditCurrentPage = () => administratorAccess || pageAccess[state.view] === 'edit';
const initialPeriod = urlState.get('period');
const initialMode = urlState.get('mode');
if (/^\d{4}-\d{2}$/.test(initialPeriod || '')) $('#period').value = initialPeriod;
if (['mtd', 'ytd'].includes(initialMode || '')) $('#mode').value = initialMode;
const d365SyncStorageKey = 'finops.d365.sync.progress';
let d365ProgressTimer = null;
const api = async path => {
    const response = await fetch(path, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (response.status === 401) {
        window.location.assign('/login');
        throw new Error('Sesi berakhir. Silakan masuk kembali.');
    }
    const text = await response.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch { payload = null; }
    if (!response.ok) throw new Error(payload?.message || payload?.error || `Request failed: ${response.status}`);
    return payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
};
const postJson = async (path, body) => {
    const response = await fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: JSON.stringify(body) });
    const text = await response.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch { payload = null; }
    if (!response.ok) throw new Error(payload?.message || (response.status === 419 ? 'Session expired. Refresh the page and try again.' : `Request failed: ${response.status}`));
    return payload?.data ?? payload;
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

function readD365SyncState() { try { return JSON.parse(localStorage.getItem(d365SyncStorageKey) || 'null'); } catch { return null; } }
function writeD365SyncState(value) { localStorage.setItem(d365SyncStorageKey, JSON.stringify(value)); }
function clearD365ProgressPanel() { document.querySelector('#d365ProgressPanel')?.remove(); if (d365ProgressTimer) { clearInterval(d365ProgressTimer); d365ProgressTimer = null; } }
function renderD365ProgressPanel(state, entities) {
    let panel = $('#d365ProgressPanel');
    if (!panel) { panel = document.createElement('section'); panel.id = 'd365ProgressPanel'; panel.className = 'd365-progress-panel'; document.body.appendChild(panel); }
    const baseline = state.baseline || {};
    const logOf = item => item.last || item;
    const changed = entities.filter(item => (logOf(item)?.updated_at || null) !== (baseline[item.key] || null));
    const running = changed.filter(item => logOf(item)?.status === 'running');
    const completed = changed.filter(item => ['success', 'failed', 'stopped'].includes(logOf(item)?.status));
    const finished = (changed.length === entities.length || state.stopRequested) && running.length === 0;
    const failed = changed.filter(item => logOf(item)?.status === 'failed');
    const stopped = state.stopRequested || changed.some(item => logOf(item)?.status === 'stopped');
    const percent = entities.length ? Math.round((completed.length / entities.length) * 100) : 0;
    const totalFetched = entities.reduce((total, item) => total + Number(logOf(item)?.fetched || 0), 0);
    const totalInserted = entities.reduce((total, item) => total + Number(logOf(item)?.inserted || 0), 0);
    const totalUpdated = entities.reduce((total, item) => total + Number(logOf(item)?.updated || 0), 0);
    const totalStaging = entities.reduce((total, item) => total + Number(item.staging_count || 0), 0);
    const resultClass = stopped ? 'is-stopped' : failed.length ? 'is-warning' : 'is-success';
    const resultLabel = stopped ? 'Sync stopped' : failed.length ? 'Sync finished with errors' : 'Sync completed successfully';
    panel.innerHTML = `<div class="d365-progress-heading"><div><strong>D365 sync progress</strong><span>${esc(state.period || 'Selected month')} · ${finished ? resultLabel : 'Running'}</span></div><div class="d365-progress-actions"><button id="closeD365Progress" class="btn btn-outline" type="button">Close</button>${finished ? '' : '<button id="stopD365Progress" class="btn btn-danger" type="button">Stop Sync</button>'}</div></div>${finished ? `<div class="d365-progress-result ${resultClass}"><strong>${resultLabel}</strong><span>${completed.length} of ${entities.length} entities completed${failed.length ? ` · ${failed.length} failed` : ''}</span></div>` : ''}<div class="d365-progress-track"><i style="width:${finished ? 100 : percent}%"></i></div><p class="d365-progress-summary">${finished ? 'All entity operations have returned. Review the per-entity status below.' : `${completed.length} of ${entities.length} entities complete${running.length ? ` · Current: ${esc(running[0].label)}` : ''}`}</p><div class="d365-progress-numbers"><span>Staging <b>${qty(totalStaging)}</b></span><span>Fetched <b>${qty(totalFetched)}</b></span><span>Inserted <b>${qty(totalInserted)}</b></span><span>Updated <b>${qty(totalUpdated)}</b></span></div><div class="d365-progress-list">${entities.map(item => { const log = logOf(item); const current = changed.some(row => row.key === item.key); const status = current ? (log?.status || 'pending') : 'waiting'; return `<div><span>${esc(item.label)}<small>${qty(item.staging_count || 0)} staging · ${qty(log?.fetched || 0)} fetched · ${qty(log?.inserted || 0)} inserted · ${qty(log?.updated || 0)} updated${log?.message ? `<br>${esc(log.message)}` : ''}</small></span><b class="status ${status === 'success' ? 'online' : status === 'failed' || status === 'stopped' ? 'manual' : status === 'running' && current ? 'online' : 'manual'}">${esc(status)}</b></div>`; }).join('')}</div>`;
    $('#closeD365Progress').addEventListener('click', () => { localStorage.removeItem(d365SyncStorageKey); clearD365ProgressPanel(); });
    if (!finished) $('#stopD365Progress').addEventListener('click', async event => { event.currentTarget.disabled = true; state.stopRequested = true; writeD365SyncState(state); try { await fetch('/api/d365/sync/stop', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } }); } finally { await refreshD365Progress(); } });
    if (finished) panel.classList.add('is-finished'); else panel.classList.remove('is-finished');
    return finished;
}
async function refreshD365Progress() {
    const syncState = readD365SyncState();
    if (!syncState) { clearD365ProgressPanel(); return; }
    try {
        const response = await fetch('/api/d365/progress', { cache: 'no-store' });
        if (!response.ok) throw new Error(`Progress request failed: ${response.status}`);
        const payload = await response.json();
        const entities = payload.data?.entities || payload.entities || [];
        const finished = renderD365ProgressPanel(syncState, entities);
        if (finished && !syncState.finished) { syncState.finished = true; writeD365SyncState(syncState); }
    } catch (error) { console.error(error); }
}
function mountD365ProgressMonitor() { if (d365ProgressTimer) clearInterval(d365ProgressTimer); if (!readD365SyncState()) { clearD365ProgressPanel(); return; } refreshD365Progress(); d365ProgressTimer = setInterval(refreshD365Progress, 2000); }

function updateNavigation(period, mode) {
    const periodText = $('#periodText');
    if (periodText) periodText.textContent = formatPeriod(period);
    $('#viewText').textContent = `${mode.toUpperCase()} · ${formatPeriod(period)}`;
    document.querySelectorAll('.mode-toggle [data-mode]').forEach(button => { const active = button.dataset.mode === mode; button.classList.toggle('active', active); button.setAttribute('aria-pressed', active ? 'true' : 'false'); });
    document.querySelectorAll('.tabs [data-view]').forEach(tab => { const active = tab.dataset.view === state.view; tab.classList.toggle('active', active); if (active) tab.setAttribute('aria-current', 'page'); else tab.removeAttribute('aria-current'); });
    history.replaceState(null, '', `/?view=${state.view}&period=${period}&mode=${mode}`);
}

async function overview(period, mode) {
    const [cost, inventory, production, dailyData] = await Promise.all([
        api(`/api/cost-matrix?period=${period}&mode=${mode}`),
        api(`/api/inventory-valuation?period=${period}&mode=${mode}`),
        api(`/api/production-summary?period=${period}&mode=${mode}`),
        api(`/api/overview-daily?period=${period}&mode=${mode}`),
    ]);
    if (period === '2026-10') {
        if (!['Google Sheets sync', 'Google Sheets'].includes(production.dataSource)) throw new Error('October production must come from the Google Sheets sync. Baseline values are disabled for October.');
        if (!(cost.d365Periods || []).includes('2026-10')) throw new Error('October costs must come from imported D365 GL. Baseline values are disabled for October.');
        if (inventory.dynamicSourceStatus !== 'active') throw new Error('October inventory requires live Google Sheets movements and imported D365 GL. Baseline values are disabled for October.');
    }
    const metricValue = key => production.dbMetrics?.[key]?.value == null ? null : Number(production.dbMetrics[key].value);
    const number = value => reportAmount(value);
    const flowQty = value => value == null || !Number.isFinite(Number(value)) ? '—' : signedNumber(value, 0);
    const flowRate = value => value == null || !Number.isFinite(Number(value)) ? '—' : signedNumber(value, 2, 2);
    const flows = production.activityFlows || [];
    const stages = inventory.stages || [];
    const stage = id => stages.find(item => item.id === id);
    const aggregate = ids => {
        const selected = stages.filter(item => ids.includes(item.id));
        const qtyMT = selected.reduce((sum, item) => sum + Number(item.endingQtyMT || 0), 0);
        const valueUSD = selected.reduce((sum, item) => sum + Number(item.endingValueUSD || 0), 0);
        return { qty: qtyMT, value: valueUSD, rate: qtyMT ? valueUSD / qtyMT : null, stages: selected };
    };
    const buaStock = aggregate(['bua']);
    const washStock = aggregate(['nonWash', 'washed', 'highAsh']);
    const portStock = aggregate(['port', 'transitPort']);
    const ispStock = aggregate(['kunz', 'transitKunz']);
    const amount = value => `USD ${number(value)}`;
    const stockLine = item => `${flowQty(item.qty)} MT${item.rate == null ? '' : ` · USD ${flowRate(item.rate)} / MT`}`;
    const lines = items => items.map(([name, value, sourceName = false]) => `<div class="overview-detail-line"><span${sourceName ? ' data-locale-ignore' : ''}>${esc(name)}</span><strong>${value}</strong></div>`).join('');
    const detail = rows => `<div>${lines(rows)}</div>`;
    const dailyDate = new Date(`${period}-01T00:00:00`);
    const lastDay = new Date(dailyDate.getFullYear(), dailyDate.getMonth() + 1, 0).getDate();
    const defaultDate = `${period}-${String(lastDay).padStart(2, '0')}`;
    const flowSitesAvailable = mode !== 'ytd' || (production.activityDetailPeriods || []).length >= 10;
    const siteBreakdown = flowSitesAvailable ? ['Takalot', 'North G'].map(location => [location, `${flowQty(flows.filter(row => row.activity === 'Coal Getting' && row.location === location).reduce((sum, row) => sum + Number(row.value || 0), 0))} MT`, true]) : [['Location split', 'October detail only; YTD source unavailable']];
    const portSales = ['port', 'transitPort'].flatMap(id => stage(id)?.rows || []).filter(row => row.type === 'outflow' && row.source === 'sale');
    const ispSales = ['kunz', 'transitKunz'].flatMap(id => stage(id)?.rows || []).filter(row => row.type === 'outflow' && row.source === 'sale');
    const salesQty = rows => Math.abs(rows.reduce((sum, row) => sum + Number(row.qtyMT || 0), 0));
    const soldQty = salesQty([...portSales, ...ispSales]);
    const soldCost = Math.abs([...portSales, ...ispSales].reduce((sum, row) => sum + Number(row.valueUSD || 0), 0));
    const coalGetting = metricValue('coalGetting');
    const miningRate = coalGetting > 0 ? Number(cost.grandTotal.costMining || 0) / coalGetting : null;
    const washFeed = metricValue('washFeed');
    const washReject = metricValue('washReject');
    const washCoal = metricValue('washCoal');
    const buaWp = metricValue('haulingBuaWp');
    const buaPort = metricValue('haulingBuaPort');
    const wpPort = metricValue('haulingWpPort');
    const combinedPortHauling = Number(cost.grandTotal.costHaulingBUAToPort || 0) + Number(cost.grandTotal.costHaulingWPToPort || 0);
    const combinedPortVolume = Number(buaPort || 0) + Number(wpPort || 0);
    const combinedPortRate = combinedPortVolume ? combinedPortHauling / combinedPortVolume : null;
    const dailySelected = dailyData.daily.find(row => row.date === defaultDate) || dailyData.daily.at(-1);
    const overviewActions = `<label>Overview view<select id="overview-date-mode"><option value="period">MTD / YTD</option><option value="daily">Daily</option></select></label><label id="overview-date-label" hidden>Date<input id="overview-date" type="date" value="${esc(defaultDate)}" min="${period}-01" max="${period}-${String(lastDay).padStart(2, '0')}"></label>`;
    const flowScenes = {
        'Coal Mining': `<svg viewBox="0 0 240 84" role="img" aria-label="Open pit mining and BUA stockpile"><path d="M0 43h45l14 10h26l14 10h35l16 9h90v12H0z" fill="#d9c5a5"/><path d="M0 52h54l12 9h30l15 10h45l13 9h71v4H0z" fill="#b58a59"/><path d="M0 65h62l13 8h45l12 8h108v3H0z" fill="#715a49"/><path d="M0 44h45m14 9h26m15 10h35m16 9h39" fill="none" stroke="#fff4df" stroke-width="2"/><path d="M154 75q14-22 30 0z" fill="#26364c"/><path d="M102 46h24l9 8h-28z" fill="#f3a72f"/><path d="M108 45l-8-13 3-2 12 14m7 2 12-12 3 2-8 14" fill="none" stroke="#e38c19" stroke-width="4" stroke-linecap="round"/><path d="M129 50l9 3-4 5-8-2" fill="#d77c12"/><rect x="104" y="53" width="31" height="4" rx="2" fill="#34465a"/><circle cx="111" cy="59" r="3" fill="#26364c"/><circle cx="129" cy="59" r="3" fill="#26364c"/><circle cx="220" cy="17" r="8" fill="#f3d487"/><path d="M198 30h30" stroke="#d8e5ee" stroke-width="2"/></svg>`,
        'Washing Plant': `<svg viewBox="0 0 240 84" role="img" aria-label="Coal washing plant and stockpiles"><path d="M0 69h240v15H0z" fill="#e6edf2"/><path d="M9 69q16-27 32 0zm29 0q18-35 36 0z" fill="#36495a"/><path d="M54 65h40l18-18h17v22H54z" fill="#d9e6ef" stroke="#91aabd" stroke-width="2"/><path d="M61 65l43-20" stroke="#e7a52a" stroke-width="4"/><path d="M103 25h28v44h-28z" fill="#f0b34a"/><path d="M109 32h16v8h-16zm0 13h16v8h-16z" fill="#fff2d4"/><path d="M136 38h27v31h-27z" fill="#708ba1"/><path d="M141 31h17v7h-17z" fill="#536f86"/><path d="M168 48h25v21h-25z" fill="#cad8e2" stroke="#91aabd" stroke-width="2"/><path d="M173 48v-9h15v9" fill="none" stroke="#91aabd" stroke-width="3"/><path d="M194 69q16-24 32 0z" fill="#3b4f61"/><circle cx="32" cy="22" r="7" fill="#c6dce9"/><path d="M25 22h14m-7-7v14" stroke="#fff" stroke-width="2"/></svg>`,
        'BP Port': `<svg viewBox="0 0 240 84" role="img" aria-label="BP Port stockpile and coal loading berth"><path d="M0 66h240v18H0z" fill="#d8eef1"/><path d="M0 74q18-5 36 0t36 0t36 0t36 0t36 0t36 0t24 0" fill="none" stroke="#69b9c4" stroke-width="2"/><path d="M10 65q18-30 36 0zm30 0q18-40 36 0z" fill="#384b5b"/><path d="M91 65h38l18-22h13" fill="none" stroke="#526d82" stroke-width="5"/><path d="M147 43V19h7v24m-17-2h31m-25 0v23m20-23v23" fill="none" stroke="#e59d2c" stroke-width="4"/><path d="M125 61h72l-12 13h-48z" fill="#32536a"/><path d="M139 59h42v7h-42z" fill="#f1f5f8"/><path d="M146 53h12v6h-12zm14 0h12v6h-12z" fill="#e3a33d"/><path d="M207 26h20v39h-20z" fill="#91aabd"/><path d="M211 33h12m-12 8h12m-12 8h12" stroke="#fff" stroke-width="2"/></svg>`,
        'ISP': `<svg viewBox="0 0 240 84" role="img" aria-label="ISP coal stockpile and transshipment barge"><path d="M0 66h240v18H0z" fill="#d8eef1"/><path d="M0 75q18-5 36 0t36 0t36 0t36 0t36 0t36 0t24 0" fill="none" stroke="#69b9c4" stroke-width="2"/><path d="M14 65q18-28 36 0zm31 0q17-37 34 0z" fill="#384b5b"/><path d="M83 63h110l-15 13h-80z" fill="#31546b"/><path d="M101 53h19v10h-19zm22-5h19v15h-19zm22 7h17v6h-17z" fill="#d99536"/><path d="M181 62V26h5v36m-15-2h30m-25-17h18" fill="none" stroke="#758fa4" stroke-width="3"/><path d="M193 47l12 15" stroke="#758fa4" stroke-width="3"/><circle cx="218" cy="18" r="8" fill="#f3d487"/><path d="M210 18h16m-8-8v16" stroke="#fff" stroke-width="2"/></svg>`,
        'Mother Vessel': `<svg viewBox="0 0 240 84" role="img" aria-label="Coal cargo ship at sea"><path d="M0 65q20-8 40 0t40 0t40 0t40 0t40 0t40 0v19H0z" fill="#d8eef1"/><path d="M0 74q20-7 40 0t40 0t40 0t40 0t40 0t40 0t40 0" fill="none" stroke="#69b9c4" stroke-width="2"/><path d="M30 54h177l-20 19H53z" fill="#29465d"/><path d="M53 49h25v11H53zm29-9h26v20H82zm30 5h25v15h-25zm29-11h29v26h-29z" fill="#df9a34"/><path d="M58 46h19v3H58zm30-9h14v3H88zm31 5h14v3h-14zm35-14h18v3h-18z" fill="#f3c56d"/><path d="M44 54V31h3v23m-8-1h20m-11-12h13" fill="none" stroke="#708ca2" stroke-width="2.5"/><path d="M195 52h27l-7 7h-25" fill="#e8edf1"/><circle cx="213" cy="17" r="8" fill="#f3d487"/><path d="M205 17h16m-8-8v16" stroke="#fff" stroke-width="2"/></svg>`
    };
    const card = (title, value, sub, rows, qtyValue = null) => `<details class="overview-detail-card" data-overview-card="${esc(title)}"><summary><span data-locale-ignore>${esc(title)}</span><div class="overview-scene">${flowScenes[title] || ''}</div><div class="overview-card-values">${qtyValue ? `<strong class="overview-stage-qty">${qtyValue}</strong>` : ''}<strong class="overview-stage-rate">${value}</strong></div><small class="overview-card-sub">${esc(sub)} · Select for details</small></summary>${detail(rows)}</details>`;
    const flowMarkup = `<section class="overview-flow-panel"><div class="overview-routes-heading"><strong>Production</strong><span><i class="flow-key-main"></i> Main flow &nbsp;&nbsp; <i class="flow-key-direct"></i> Direct route · ${mode.toUpperCase()} ${esc(formatPeriod(period))}<small class="overview-source-chip">Google Sheets ${production.sheetLastSyncedAt ? `· synced ${esc(formatSyncTimestamp(production.sheetLastSyncedAt))}` : '· synced data'}</small></span></div><div class="overview-flow-scroll"><div class="overview-flow-map"><div class="overview-flow-cards">${card('Coal Mining', miningRate == null ? '—' : `USD ${flowRate(miningRate)} / MT`, `Coal getting · BUA closing ${stockLine(buaStock)}`, [['Coal Getting', coalGetting == null ? '—' : `${flowQty(coalGetting)} MT`], ...siteBreakdown, ['Waste Removal', metricValue('wasteRemoval') == null ? '—' : `${flowQty(metricValue('wasteRemoval'))} Bcm`], ['BUA inventory closing', stockLine(buaStock)]], coalGetting == null ? '—' : `${flowQty(coalGetting)} MT`)}${card('Washing Plant', '', `Washed coal production · WP closing ${stockLine(washStock)}`, [['Raw coal available', `${flowQty(Number(stage('nonWash')?.availableQtyMT || 0))} MT`], ['Feed to washing', washFeed == null ? '—' : `${flowQty(washFeed)} MT`], ['Clean coal produced · feed less reject', washCoal == null ? '—' : `${flowQty(washCoal)} MT`], ['Reject (reduces Wash only)', washReject == null ? '—' : `${flowQty(washReject)} MT`], ['Ending raw coal', stage('nonWash') ? stockLine({qty:Number(stage('nonWash').endingQtyMT || 0),rate:stage('nonWash').endingRateUSD}) : '—'], ['Ending clean coal', stage('washed') ? stockLine({qty:Number(stage('washed').endingQtyMT || 0),rate:stage('washed').endingRateUSD}) : '—'], ['Ending reject stock', stage('highAsh') ? stockLine({qty:Number(stage('highAsh').endingQtyMT || 0),rate:stage('highAsh').endingRateUSD}) : '—']], washCoal == null ? '—' : `${flowQty(washCoal)} MT`)}${card('BP Port', portStock.rate == null ? '—' : `USD ${flowRate(portStock.rate)} / MT`, `Closing inventory · includes transit ${stockLine(portStock)}`, portStock.stages.map(item => [item.name || item.id, stockLine({qty:Number(item.endingQtyMT || 0),rate:item.endingRateUSD}), true]), `${flowQty(portStock.qty)} MT`)}${card('ISP', ispStock.rate == null ? '—' : `USD ${flowRate(ispStock.rate)} / MT`, `Closing inventory · includes transit ${stockLine(ispStock)}`, ispStock.stages.map(item => [item.name || item.id, stockLine({qty:Number(item.endingQtyMT || 0),rate:item.endingRateUSD}), true]), `${flowQty(ispStock.qty)} MT`)}${card('Mother Vessel', soldQty > 0 ? `USD ${flowRate(soldCost / soldQty)} / MT` : '—', 'Sales volume · inventory cost per MT', [['From BP Port', `${flowQty(salesQty(portSales))} MT`], ['From ISP', `${flowQty(salesQty(ispSales))} MT`]], `${flowQty(soldQty)} MT`)}</div><div class="overview-bypass" aria-label="Direct hauling from BUA to Port"><svg viewBox="0 0 1000 90" preserveAspectRatio="none" aria-hidden="true"><path d="M68 0 V48 Q68 70 88 70 H480 Q500 70 500 48 V4"/></svg></div><div class="overview-vessel-direct" aria-label="Direct sales from BP Port to Mother Vessel"><svg viewBox="0 0 1000 70" preserveAspectRatio="none" aria-hidden="true"><path d="M500 70 V28 Q500 8 520 8 H912 Q932 8 932 28 V65"/></svg><span>BP PORT → MOTHER VESSEL</span></div><details class="overview-route route-bua-wp" data-overview-route="buaWp"><summary><span>BUA → WP</span><div><strong>${buaWp == null ? '—' : `${flowQty(buaWp)} MT`}</strong><b>${buaWp && buaWp !== 0 ? `USD ${flowRate(Number(cost.grandTotal.costHaulingBUAToWP || 0) / buaWp)} / MT` : '—'}</b></div></summary><p>Costing to BUA→WP divided by Google Sheets route volume.</p></details><details class="overview-route route-wp-port" data-overview-route="wpPort"><summary><span>WP → Port</span><div><strong>${wpPort == null ? '—' : `${flowQty(wpPort)} MT`}</strong><b>${combinedPortRate == null ? '—' : `USD ${flowRate(combinedPortRate)} / MT`}</b></div></summary><p>Temporary estimate: combined port hauling cost allocated by route volume.</p></details><details class="overview-route route-bua-port" data-overview-route="buaPort"><summary><span>BUA → Port</span><div><strong>${buaPort == null ? '—' : `${flowQty(buaPort)} MT`}</strong><b>${combinedPortRate == null ? '—' : `USD ${flowRate(combinedPortRate)} / MT`}</b></div></summary><p>Google Sheets hauling volume; temporary estimate uses combined port hauling cost per route MT.</p></details></div></div><div class="overview-flow-detail" hidden></div>${mode === 'ytd' && !flowSitesAvailable ? '<p class="overview-daily-note">Rincian produksi per lokasi yang tersinkron tersedia mulai Oktober saja. Total YTD memakai nilai bulanan DB Google Sheets; rincian Takalot/North G tidak diisi dengan angka MTD.</p>' : ''}</section>`;
    $('#pageContent').innerHTML = `${sectionHeader('Operations & Financial Overview', 'October onward: synced D365 GL and Google Sheets. January–September: historical baseline.', overviewActions)}${flowMarkup}<div class="grid-2 overview-lower"><section class="card"><div class="card-title"><span>Cost Allocation: COGP &amp; Direct Expense</span>${badge(mode === 'ytd' ? 'D365 GL Oct + Jan–Sep baseline' : 'D365 GL sync')}<small>USD</small></div><div class="allocation-grid"><button type="button" id="overview-filter-expense" class="allocation-val overview-allocation-tile" aria-pressed="false"><b>DIRECT EXPENSE, GA &amp; SELLING</b><strong>${money(cost.grandTotal.totalExpense)}</strong><span>Direct Expense by Valuation to; Not Valuated is included in GA. Selling Expense has its own column.</span></button><button type="button" id="overview-filter-cogp" class="allocation-cost overview-allocation-tile" aria-pressed="true"><b>COGP</b><strong>${money(cost.grandTotal.totalCOGP)}</strong><span>From Costing to: Mining, 3 Coal Hauling routes, Washing Plant, BP PORT</span></button></div><div class="overview-driver-tools"><b>Top Cost Drivers</b><button type="button" id="overview-filter-all" class="btn btn-outline overview-filter-all" aria-pressed="false">All Cost</button><label>Group by<select id="overview-allocation-group"><option value="account">Account</option><option value="location">Location</option><option value="activity">Activity</option><option value="department">Department</option></select></label></div><p id="overview-allocation-note" class="integration-note"></p><div id="overview-top-drivers"></div></section><section class="card"><div class="card-title"><span>Daily Production Volume (MT)</span>${badge('Google Sheets Data')}</div><p class="chart-caption">Daily recorded coal volume (MT):</p><label class="overview-production-control">Production view<select id="overview-production-view"><option value="production">Coal Production</option><option value="hauling">Coal Hauling</option><option value="selling">Selling</option></select></label><div id="chart-daily-production"></div><div class="card-title overview-daily-cost-title">Daily Cost — COGP &amp; GA</div><p id="overview-direct-note" class="integration-note"></p><div id="overview-direct-chart"></div><details class="overview-daily-details"><summary><strong>View daily cost details</strong></summary>${table(['Date','Mining estimate (USD)','Hauling contractor (USD)','Other hauling GL (USD)','COGP shown (USD)','Mining fuel portion (USD)','GA GL movement (USD)'],'<tr><td colspan="7">Loading daily details…</td></tr>')}</details><div class="chart-footer"><span>COGP: Google Sheets production + DB rates + D365 GL hauling</span><span>GA: D365 GL</span></div></section></div>`;
    $('.overview-daily-details thead')?.setAttribute('data-locale-ignore', '');
    const flowPanel = $('.overview-flow-panel');
    mountProductionMap(flowPanel, { card, buaStock, stockLine, siteBreakdown, flowSitesAvailable, stages, period, mode, dailyData, dbMetrics: production.dbMetrics || {}, api, qty: flowQty, number, flowRate });
    const vesselCard = $('[data-overview-card="Mother Vessel"]');
    if (vesselCard && !soldQty) vesselCard.querySelector('.overview-card-sub').textContent = 'No sales quantity in live October inventory source · Select for details';
    let allocationRenderVersion = 0;
    const renderAllocation = async () => {
        const renderVersion = ++allocationRenderVersion;
        const startedAt = performance.now();
        const group = $('#overview-allocation-group').value;
        const active = document.querySelector('.overview-allocation-tile[aria-pressed="true"]')?.id;
        const bucket = active === 'overview-filter-expense' ? 'expense' : active === 'overview-filter-cogp' ? 'cogp' : 'all';
        const title = bucket === 'cogp' ? 'COGP' : bucket === 'expense' ? 'Direct Expense, GA & Selling' : 'All costs';
        const groupLabel = group.charAt(0).toUpperCase() + group.slice(1);
        $('#overview-allocation-note').textContent = `${title} · Top 10 by absolute amount. October uses imported D365 GL; YTD historical months follow the approved source.`;
        const driverList = $('#overview-top-drivers');
        driverList.setAttribute('aria-busy', 'true');
        driverList.innerHTML = `<div class="overview-allocation-loading" role="status" aria-live="polite"><span class="overview-allocation-spinner" aria-hidden="true"></span><span><strong>Updating allocation</strong><small>Calculating ${esc(title)} by ${esc(groupLabel)}…</small></span></div>`;
        let entries = [];
        let allocationError = null;
        try {
            if (group === 'account') {
                const field = bucket === 'cogp' ? 'totalCOGP' : bucket === 'expense' ? 'totalExpense' : 'allCost';
                entries = cost.rows.filter(row => !row.isParent && Math.abs(Number(row[field] || 0)) > 0.005).map(row => [`${row.code} ${row.name}`, Number(row[field])]);
            } else {
                $('#overview-allocation-note').textContent = `${title} · Aggregated from imported D365 GL dimensions only.`;
                const result = await api(`/api/overview-allocation?period=${period}&mode=${mode}&group=${group}`);
                if (renderVersion !== allocationRenderVersion) return;
                entries = Object.entries(result.totals[bucket] || {}).map(([name, value]) => [name, Number(value)]);
            }
        } catch (error) {
            allocationError = error;
        }
        const remainingLoadingMs = Math.max(0, 180 - (performance.now() - startedAt));
        if (remainingLoadingMs) await new Promise(resolve => setTimeout(resolve, remainingLoadingMs));
        if (renderVersion !== allocationRenderVersion) return;
        driverList.setAttribute('aria-busy', 'false');
        if (allocationError) {
            driverList.innerHTML = `<p class="overview-allocation-error" role="alert">Could not load allocation: ${esc(allocationError.message || 'Please try again.')}</p>`;
            return;
        }
        entries.sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]));
        const top = entries.slice(0, 10);
        driverList.innerHTML = top.map(([name, value]) => {
            return `<div class="driver-row"><span data-locale-ignore>${esc(name)}</span><b>${money(value)}</b></div>`;
        }).join('') || '<p>No data for this selection.</p>';
    };
    document.querySelectorAll('.overview-allocation-tile').forEach(button => button.addEventListener('click', () => {
        if (button.getAttribute('aria-pressed') === 'true') {
            button.setAttribute('aria-pressed', 'false');
            $('#overview-filter-cogp').setAttribute('aria-pressed', 'false');
            $('#overview-filter-expense').setAttribute('aria-pressed', 'false');
        } else {
            document.querySelectorAll('.overview-allocation-tile').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        }
        renderAllocation();
    }));
    $('#overview-filter-all').addEventListener('click', event => {
        const next = event.currentTarget.getAttribute('aria-pressed') !== 'true';
        event.currentTarget.setAttribute('aria-pressed', String(next));
        if (next) document.querySelectorAll('.overview-allocation-tile').forEach(item => item.setAttribute('aria-pressed', 'false'));
        else $('#overview-filter-cogp').setAttribute('aria-pressed', 'true');
        renderAllocation();
    });
    $('#overview-allocation-group').addEventListener('change', renderAllocation);
    await renderAllocation();

    const chartDate = () => $('#overview-date').value || defaultDate;
    const dailyControls = () => {
        const dailyMode = $('#overview-date-mode').value === 'daily';
        $('#overview-date-label').hidden = !dailyMode;
        renderOverviewCharts();
    };
    const renderOverviewCharts = () => {
        const isDaily = $('#overview-date-mode').value === 'daily';
        const chosenDate = chartDate();
        const view = $('#overview-production-view').value;
        const productionByDate = new Map((dailyData.daily || []).map(row => [row.date, row]));
        const costsByDate = new Map((dailyData.costs || []).map(row => [row.date, row]));
        const chartDates = [...new Set([...productionByDate.keys(), ...costsByDate.keys()])]
            .filter(date => !isDaily || date === chosenDate).sort();
        const dailyRows = chartDates.map(date => productionByDate.get(date) || {date, coal:null, ob:null, hauling:null});
        const costRows = chartDates.map(date => costsByDate.get(date) || {date, cogp:null, fuel:null, haulingContractor:null, haulingGL:null, ga:0, gaAvailable:false});
        const selectedDay = productionByDate.get(chosenDate);
        const mineCard = $('[data-overview-card="Coal Mining"]');
        if (mineCard) {
            mineCard.querySelector('.overview-stage-qty').textContent = isDaily ? (selectedDay?.coal == null ? '—' : `${flowQty(selectedDay.coal)} MT`) : `${flowQty(metricValue('coalGetting'))} MT`;
            mineCard.querySelector('.overview-stage-rate').textContent = isDaily || miningRate == null ? '—' : `USD ${flowRate(miningRate)} / MT`;
            mineCard.querySelector('.overview-card-sub').textContent = `${isDaily ? `Daily coal getting · BUA closing ${stockLine(buaStock)}` : `Coal getting · BUA closing ${stockLine(buaStock)}`} · Select for details`;
        }
        const washCard = $('[data-overview-card="Washing Plant"]');
        if (washCard) {
            washCard.querySelector('.overview-stage-qty').textContent = isDaily ? '—' : (washCoal == null ? '—' : `${flowQty(washCoal)} MT`);
            washCard.querySelector('.overview-stage-rate').textContent = '';
            washCard.querySelector('.overview-card-sub').textContent = `${isDaily ? 'Daily production unavailable' : 'Washed coal production'} · WP closing ${stockLine(washStock)} · Select for details`;
        }
        if (vesselCard && isDaily) { vesselCard.querySelector('.overview-stage-qty').textContent = '—'; vesselCard.querySelector('.overview-stage-rate').textContent = '—'; vesselCard.querySelector('.overview-card-sub').textContent = 'Daily sales unavailable · Select for details'; }
        const routeValues = {buaWp, wpPort, buaPort};
        flowPanel.querySelectorAll('[data-overview-route]').forEach(route => {
            const key = route.dataset.overviewRoute;
            const volume = route.querySelector('summary strong');
            const rate = route.querySelector('summary b');
            if (isDaily) { volume.textContent = '—'; rate.textContent = '—'; }
            else if (key === 'buaWp') { volume.textContent = routeValues.buaWp == null ? '—' : `${flowQty(routeValues.buaWp)} MT`; rate.textContent = routeValues.buaWp ? `USD ${flowRate(Number(cost.grandTotal.costHaulingBUAToWP || 0) / routeValues.buaWp)} / MT` : '—'; }
            else { const amount = key === 'wpPort' ? routeValues.wpPort : routeValues.buaPort; volume.textContent = amount == null ? '—' : `${flowQty(amount)} MT`; rate.textContent = combinedPortRate == null ? '—' : `USD ${flowRate(combinedPortRate)} / MT`; }
        });
        const maxDays = Math.max(1, ...dailyRows.map(row => view === 'hauling' ? Number(row.hauling || 0) : Number(row.coal || 0) + Number(row.ob || 0)));
        const chartRows = dailyRows;
        const totalCoal = chartRows.reduce((sum, row) => sum + Number(row.coal || 0), 0);
        const totalOb = chartRows.reduce((sum, row) => sum + Number(row.ob || 0), 0);
        const totalHauling = chartRows.reduce((sum, row) => sum + Number(row.hauling || 0), 0);
        if (view === 'selling') {
            $('#chart-daily-production').innerHTML = '<p class="overview-daily-note">Daily selling data is not available. Inventory sales inputs are monthly and cannot be assigned to a day.</p>';
        } else if (!chartRows.length) {
            $('#chart-daily-production').innerHTML = '<p class="chart-empty">No daily Google Sheets data for the selected date range.</p>';
        } else {
            const width = Math.max(550, chartRows.length * 38 + 136), height = 230, pad = 68;
            const bars = chartRows.map((row, index) => {
                const x = pad + index * 38 + 10;
                const coalHeight = view === 'hauling' ? 0 : Number(row.coal || 0) / maxDays * 150;
                const obHeight = view === 'hauling' ? Number(row.hauling || 0) / maxDays * 150 : Number(row.ob || 0) / maxDays * 150;
                const primary = view === 'hauling' ? Number(row.hauling || 0) : Number(row.coal || 0);
                const dateLabel = row.date.slice(8);
                return view === 'hauling'
                    ? `<g class="production-bar" data-index="${index}" data-date="${esc(row.date)}" data-coal="${esc(row.coal ?? '')}" data-ob="${esc(row.ob ?? '')}" data-hauling="${esc(row.hauling ?? '')}" tabindex="0" role="button" aria-pressed="false" aria-label="${esc(formatDate(row.date))}: Coal hauling ${qty(primary)} MT"><rect class="bar-value" x="${x}" y="${height-pad-obHeight}" width="20" height="${obHeight}" rx="3" fill="#2563eb"><title>${esc(row.date)}: Hauling ${qty(primary)} MT</title></rect><text x="${x+10}" y="${Math.max(12,height-pad-obHeight-5)}" text-anchor="middle" font-size="8" fill="#334155">${qty(primary)}</text><text x="${x+10}" y="${height-14}" text-anchor="middle" font-size="9" fill="#64748b">${dateLabel}</text><rect class="bar-hit" x="${x-5}" y="${height-pad-150}" width="30" height="150"/></g>`
                    : `<g class="production-bar" data-index="${index}" data-date="${esc(row.date)}" data-coal="${esc(row.coal ?? '')}" data-ob="${esc(row.ob ?? '')}" data-hauling="${esc(row.hauling ?? '')}" tabindex="0" role="button" aria-pressed="false" aria-label="${esc(formatDate(row.date))}: Coal getting ${row.coal == null ? 'unavailable' : `${qty(row.coal)} MT`}; OB removal ${row.ob == null ? 'unavailable' : `${qty(row.ob)} Bcm`}"><rect class="bar-value" x="${x}" y="${height-pad-obHeight}" width="20" height="${obHeight}" fill="#14b8a6"><title>${esc(row.date)}: OB ${row.ob == null ? '—' : `${qty(row.ob)} Bcm`}</title></rect><rect class="bar-value" x="${x}" y="${height-pad-obHeight-coalHeight}" width="20" height="${coalHeight}" rx="2" fill="#2563eb"><title>${esc(row.date)}: Coal Getting ${qty(row.coal)} MT</title></rect><text x="${x+10}" y="${Math.max(12,height-pad-obHeight-coalHeight-5)}" text-anchor="middle" font-size="8" fill="#334155">${row.coal == null ? '—' : qty(row.coal)}</text>${obHeight>22 && row.ob != null ? `<text x="${x+10}" y="${height-pad-obHeight/2}" text-anchor="middle" font-size="8" fill="#fff">${Math.round(Number(row.ob)/1000)}k</text>` : ''}<text x="${x+10}" y="${height-14}" text-anchor="middle" font-size="9" fill="#64748b">${dateLabel}</text><rect class="bar-hit" x="${x-5}" y="${height-pad-150}" width="30" height="150"/></g>`;
            }).join('');
            const summary = view === 'hauling' ? `${isDaily ? 'Daily' : mode.toUpperCase()} hauling ${qty(totalHauling)} MT` : `${isDaily ? 'Daily' : mode.toUpperCase()} coal ${qty(totalCoal)} MT · OB ${dailyRows.every(row => row.ob !== null) ? `${qty(totalOb)} Bcm` : '—'} · SR ${totalCoal && dailyRows.every(row => row.ob !== null) ? (totalOb / totalCoal).toFixed(2) : '—'}`;
            const note = mode === 'ytd' && !isDaily ? '<p class="overview-daily-note">Grafik memakai baris harian yang tersedia dari Google Sheets. Total YTD pada diagram produksi memakai DB sheet; bulan tanpa rincian harian tidak dibuat dari baseline Oktober.</p>' : '';
            $('#chart-daily-production').innerHTML = `${note}<div class="overview-production-summary">${summary}</div><div class="overview-graph-legend">${view === 'hauling' ? '<span><i class="legend-swatch hauling"></i>Coal Hauling (MT) — all recorded routes</span>' : '<span><i class="legend-swatch coal"></i>Coal Getting (MT)</span><span><i class="legend-swatch ob"></i>OB Removal (Bcm)</span><span>Units are shown on a shared visual scale.</span>'}</div><div class="overview-graph-scroll"><svg viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" role="group" aria-label="Daily production chart. Hover, focus, or select a bar to inspect daily values."><line x1="${pad}" y1="${height-pad}" x2="${width-20}" y2="${height-pad}" stroke="#cbd5e1"/>${bars}</svg></div><div id="overview-production-insight" class="overview-chart-insight" role="status" aria-live="polite">Hover, focus, or select a bar to inspect that date.</div>`;
            const insight = $('#overview-production-insight');
            const barGroups = [...$('#chart-daily-production').querySelectorAll('.production-bar')];
            let selectedBar = null;
            const showBarDetails = (bar, selected = false) => {
                const coal = bar.dataset.coal === '' ? null : Number(bar.dataset.coal);
                const ob = bar.dataset.ob === '' ? null : Number(bar.dataset.ob);
                const hauling = bar.dataset.hauling === '' ? null : Number(bar.dataset.hauling);
                const values = view === 'hauling'
                    ? `<span><b>Coal hauling</b>${hauling == null ? '—' : `${qty(hauling)} MT`}</span>`
                    : `<span><b>Coal getting</b>${coal == null ? '—' : `${qty(coal)} MT`}</span><span><b>OB removal</b>${ob == null ? '—' : `${qty(ob)} Bcm`}</span>`;
                insight.innerHTML = `<strong>${esc(formatDate(bar.dataset.date))}${selected ? '<em>Selected</em>' : ''}</strong>${values}<small>${selected ? 'Click the selected bar again to clear.' : 'Click a bar to keep its details selected.'}</small>`;
            };
            const setActiveBar = bar => {
                barGroups.forEach(item => { const active = item === bar; item.classList.toggle('is-active', active); item.setAttribute('aria-pressed', String(active && selectedBar === item)); });
                showBarDetails(bar, selectedBar === bar);
            };
            barGroups.forEach(bar => {
                bar.addEventListener('pointerenter', () => { if (!selectedBar) setActiveBar(bar); });
                bar.addEventListener('focus', () => { if (!selectedBar) setActiveBar(bar); });
                bar.addEventListener('pointerleave', () => { if (!selectedBar && document.activeElement !== bar) { bar.classList.remove('is-active'); insight.innerHTML = 'Hover, focus, or select a bar to inspect that date.'; } });
                bar.addEventListener('click', () => { selectedBar = selectedBar === bar ? null : bar; if (selectedBar) setActiveBar(bar); else { bar.classList.remove('is-active'); bar.setAttribute('aria-pressed', 'false'); insight.innerHTML = 'Hover, focus, or select a bar to inspect that date.'; } });
                bar.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); bar.click(); } });
            });
        }
        $('#overview-direct-note').textContent = `${isDaily ? `Daily ${formatDate(chosenDate)}` : mode.toUpperCase()} · COGP = Google Sheets production × DB accrual/fuel rates + dated D365 GL hauling; GA is D365 GL. Days with missing source inputs are left unavailable. October onward uses synced sources only; Jan–Sep uses baseline.`;
        if (!costRows.length) {
            $('#overview-direct-chart').innerHTML = '<p class="chart-empty">No daily COGP accrual or dated D365 GL data for this selection.</p>';
        } else {
            const availableCogp = costRows.filter(row => row.cogp !== null && row.cogp !== undefined && Number.isFinite(Number(row.cogp)));
            const totalCogp = availableCogp.reduce((sum, row) => sum + Number(row.cogp), 0);
            const totalGa = costRows.reduce((sum, row) => sum + Number(row.ga || 0), 0);
            const width = Math.max(550, costRows.length * 38 + 136), height = 230, pad = 68;
            const values = costRows.flatMap(row => [row.cogp, row.ga]).filter(value => value !== null && value !== undefined && Number.isFinite(Number(value))).map(Number);
            const low = Math.min(0, ...values), high = Math.max(0, ...values), range = high - low || 1;
            const x = index => pad + index * 38 + 10, y = value => height - 40 - (value - low) / range * 145;
            const path = key => { let active = false; return costRows.map((row, index) => { const value = row[key]; if (value === null || value === undefined || !Number.isFinite(Number(value))) { active = false; return ''; } const segment = `${active ? 'L' : 'M'}${x(index).toFixed(1)},${y(Number(value)).toFixed(1)}`; active = true; return segment; }).filter(Boolean).join(' '); };
            const grid = Array.from({length:5},(_,index)=>{const value=low+range*index/4,yy=y(value);return `<line x1="${pad}" y1="${yy}" x2="${width-pad}" y2="${yy}" stroke="#e2e8f0"/><text x="${pad-7}" y="${yy+3}" text-anchor="end" font-size="9" fill="#64748b">${number(value)}</text>`;}).join('');
            const marks = costRows.map((row,index)=>`<g class="cost-chart-day" data-index="${index}" data-date="${esc(row.date)}" data-cogp="${row.cogp ?? ''}" data-fuel="${row.fuel ?? ''}" data-hauling-contractor="${row.haulingContractor ?? ''}" data-hauling-gl="${row.haulingGL ?? ''}" data-ga="${row.gaAvailable ? row.ga ?? 0 : ''}" tabindex="0" role="button" aria-pressed="false" aria-label="${esc(formatDate(row.date))}: COGP ${row.cogp == null ? 'unavailable' : money(row.cogp)}, GA ${row.gaAvailable ? money(row.ga ?? 0) : 'unavailable'}"><rect class="cost-day-hit" x="${x(index)-13}" y="38" width="26" height="158"/>${[['cogp','#2563eb'],['ga','#f59e0b']].map(([key,color])=>row[key] === null || row[key] === undefined || !Number.isFinite(Number(row[key])) ? '' : `<circle class="cost-point" cx="${x(index)}" cy="${y(Number(row[key]))}" r="3.5" fill="${color}"><title>${esc(row.date)} ${key.toUpperCase()} ${money(row[key])}</title></circle>`).join('')}${index%Math.max(1,Math.ceil(costRows.length/12))===0?`<text x="${x(index)}" y="${height-14}" text-anchor="middle" font-size="9" fill="#64748b">${esc(row.date.slice(5))}</text>`:''}</g>`).join('');
            $('#overview-direct-chart').innerHTML = `<div class="overview-graph-legend overview-cost-legend"><span><i class="legend-swatch coal"></i>COGP · ${money(totalCogp)}</span><span><i class="legend-swatch ga"></i>GA GL · ${money(totalGa)}</span><span>${availableCogp.length}/${costRows.length} complete dates</span></div><div class="overview-graph-scroll"><svg viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" role="group" aria-label="Daily COGP from Google Sheets, DB rates and D365 GL hauling; GA from D365 GL. Hover, focus, or select a date for details.">${grid}<path d="${path('cogp')}" fill="none" stroke="#2563eb" stroke-width="2.5"/><path d="${path('ga')}" fill="none" stroke="#f59e0b" stroke-width="2.5"/>${marks}</svg></div><div id="overview-cost-insight" class="overview-chart-insight" role="status" aria-live="polite">Hover, focus, or select a date to inspect cost values.</div>`;
            const costInsight = $('#overview-cost-insight');
            const costDays = [...$('#overview-direct-chart').querySelectorAll('.cost-chart-day')];
            let selectedCostDay = null;
            const showCostDetails = (day, selected = false) => {
                const value = key => day.dataset[key] === '' ? '—' : money(Number(day.dataset[key]));
                costInsight.innerHTML = `<strong>${esc(formatDate(day.dataset.date))}${selected ? '<em>Selected</em>' : ''}</strong><span><b>COGP total</b>${value('cogp')}</span><span><b>Fuel · Google Sheets + DB rate</b>${value('fuel')}</span><span><b>Contractor hauling · Google Sheets + DB rate</b>${value('hauling-contractor')}</span><span><b>Other hauling · D365 GL</b>${value('hauling-gl')}</span><span><b>GA · D365 GL</b>${value('ga')}</span><small>${selected ? 'Click the selected date again to clear.' : 'Click a date to keep its details selected.'}</small>`;
            };
            const setActiveCostDay = day => {
                costDays.forEach(item => { const active = item === day; item.classList.toggle('is-active', active); item.setAttribute('aria-pressed', String(active && selectedCostDay === item)); });
                showCostDetails(day, selectedCostDay === day);
            };
            costDays.forEach(day => {
                day.addEventListener('pointerenter', () => { if (!selectedCostDay) setActiveCostDay(day); });
                day.addEventListener('focus', () => { if (!selectedCostDay) setActiveCostDay(day); });
                day.addEventListener('pointerleave', () => { if (!selectedCostDay && document.activeElement !== day) { day.classList.remove('is-active'); costInsight.innerHTML = 'Hover, focus, or select a date to inspect cost values.'; } });
                day.addEventListener('click', () => { selectedCostDay = selectedCostDay === day ? null : day; if (selectedCostDay) setActiveCostDay(day); else { day.classList.remove('is-active'); day.setAttribute('aria-pressed', 'false'); costInsight.innerHTML = 'Hover, focus, or select a date to inspect cost values.'; } });
                day.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); day.click(); } });
            });
        }
        const productionScroll = $('#chart-daily-production').querySelector('.overview-graph-scroll');
        const costScroll = $('#overview-direct-chart').querySelector('.overview-graph-scroll');
        if (productionScroll && costScroll) {
            let syncing = false;
            const syncPosition = (source, target) => {
                if (syncing) return;
                syncing = true;
                const sourceRange = source.scrollWidth - source.clientWidth;
                const targetRange = target.scrollWidth - target.clientWidth;
                target.scrollLeft = sourceRange > 0 ? (source.scrollLeft / sourceRange) * Math.max(0, targetRange) : 0;
                syncing = false;
            };
            productionScroll.addEventListener('scroll', () => syncPosition(productionScroll, costScroll), {passive:true});
            costScroll.addEventListener('scroll', () => syncPosition(costScroll, productionScroll), {passive:true});
        }
        const body = $('.overview-daily-details tbody');
        if (body) body.innerHTML = costRows.map(row => {
            const miningEstimate = row.operation == null || row.fuel == null ? null : Number(row.operation) + Number(row.fuel);
            return `<tr><td>${esc(formatDate(row.date))}</td><td class="num">${miningEstimate == null ? '—' : money(miningEstimate)}</td><td class="num">${row.haulingContractor == null ? '—' : money(row.haulingContractor)}</td><td class="num">${row.haulingGL == null ? '—' : money(row.haulingGL)}</td><td class="num">${row.cogp == null ? '—' : money(row.cogp)}</td><td class="num">${row.fuel == null ? '—' : money(row.fuel)}</td><td class="num">${row.gaAvailable ? money(row.ga) : '—'}</td></tr>`;
        }).join('') || '<tr><td colspan="7">No daily data for this selection.</td></tr>';
    };
    $('#overview-date-mode').addEventListener('change', dailyControls);
    $('#overview-date').addEventListener('change', renderOverviewCharts);
    $('#overview-production-view').addEventListener('change', renderOverviewCharts);
    dailyControls();
}

async function cost(period, mode) {
    const data = await api(`/api/cost-matrix?period=${period}&mode=${mode}`);
    const costValueCell = value => `<td class="num ${Number(value) < 0 ? 'cost-negative' : ''}">${reportAmount(value)}</td>`;
    const closingStatus = data.reportCostClosed ? 'Yes' : 'No';
    const fields = [['costMining', 'Mining'], ['costHaulingBUAToWP', 'Coal Hauling BUA to WP'], ['costHaulingBUAToPort', 'Coal Hauling BUA to Port'], ['costHaulingWPToPort', 'Coal Hauling WP to Port'], ['costWP', 'Washing Plant'], ['costPort', 'BP Port'], ['valMining', 'Mining'], ['valHauling', 'Coal Hauling'], ['valWP', 'Washing Plant'], ['valPort', 'BP Port'], ['directGA', 'General & Administration'], ['sellingExpense', 'Selling Expense']];
    const search = state.costSearch.trim().toLowerCase();
    const visibleRows = data.rows.filter(row => (!search || `${row.code} ${row.name}`.toLowerCase().includes(search)) && (!state.costParentOnly || row.isParent) && (!state.costCollapsed[row.parent]) && (!state.costHideZero || Number(row.allCost || 0) !== 0));
    const allAccountsActive = !state.costSearch && !state.costParentOnly && !state.costHideZero;
    const unallocatedRows = data.rows.filter(row => !row.isParent && Math.abs(Number(row.unallocatedHauling || 0)) > 0.000001);
    const unallocatedTotal = unallocatedRows.reduce((sum, row) => sum + Number(row.unallocatedHauling || 0), 0);
    const rows = visibleRows.map(row => `<tr class="${row.isParent ? 'parent-row' : ''}"><td>${esc(row.code)}</td><td class="account-name">${row.isParent ? `<button type="button" class="account-toggle" data-toggle-parent="${esc(row.code)}" aria-label="${state.costCollapsed[row.code] ? 'Expand' : 'Collapse'} ${esc(row.name)}">${state.costCollapsed[row.code] ? '+' : '−'}</button>` : ''}${esc(row.name)}</td><td class="center">${reportAmount(row.cek)}</td>${costValueCell(row.allCost)}${fields.map(([field], index) => `${index === 6 ? '<td class="cost-group-spacer" aria-hidden="true"></td>' : ''}${costValueCell(row[field])}`).join('')}</tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Report Cost: COGP & Direct Expense', 'Accounts 5–6 follow Costing to. COGP totals are grouped by Mining, each coal hauling route, Washing Plant, and BP Port. Direct Expense uses Valuation to; Not Valuated is included in General & Administration. Accounts 7–9 are allocated to General & Administration. Selling Expense is shown separately. Amounts are in USD.', `<div class="cost-control-row cost-control-primary"><label class="period-control">Month <input id="costPeriod" type="month" value="${period}" aria-label="Report cost period"></label><label class="period-control">Closing <select id="costClosing" aria-label="Report Cost closing status"><option value="no" ${!data.reportCostClosed ? 'selected' : ''}>No</option><option value="yes" ${data.reportCostClosed ? 'selected' : ''}>Yes</option></select></label><button id="costImportGl" class="btn btn-outline" type="button">Import / Sync GL</button></div><div class="cost-control-row cost-control-secondary"><input id="costSearch" class="input-search" placeholder="🔍 Search account code / name..." value="${esc(state.costSearch)}"><button id="costShowAll" class="btn btn-outline ${allAccountsActive ? 'is-active' : ''}" type="button" aria-pressed="${allAccountsActive}">Show All Accounts</button><button id="costParentOnly" class="btn btn-outline ${state.costParentOnly ? 'is-active' : ''}" type="button" aria-pressed="${state.costParentOnly}">${state.costParentOnly ? 'Show All Rows' : 'Parent Accounts Only (Summary)'}</button><button id="costHideZero" class="btn btn-outline ${state.costHideZero ? 'is-active' : ''}" type="button" aria-pressed="${state.costHideZero}">${state.costHideZero ? 'Show Zero Balances' : 'Hide Zero Balances'}</button><button id="costDeleteGl" class="btn btn-outline" type="button">Delete GL Data</button><button id="costExport" class="btn btn-primary" type="button">📥 Export Excel/CSV</button></div>`)}
        <div class="cost-report-card"><p class="table-note">Showing ${visibleRows.length} of ${data.rows.length} accounts</p><div class="cost-notice">Source: ${esc(period >= '2026-10' ? (mode === 'ytd' ? 'D365 synced GL for October onward; historical baseline for January–September.' : `D365 synced GL · ${period}.`) : 'Historical baseline and available synced GL for the selected period.')} for the selected period.</div><div class="cost-notice">Closing: <strong>${closingStatus}</strong>. ${data.reportCostClosed ? 'Accruals are excluded; this period shows actual costs only.' : 'Accruals are included when available for this period.'}</div>${unallocatedRows.length ? `<div class="cost-notice">Coal Hauling is not assigned to a route for ${unallocatedRows.length} accounts (net USD ${Math.round(Math.abs(unallocatedTotal)).toLocaleString('en-US')}). This balance remains in All Cost. Set Costing as Coal Hauling BUA to WP, Coal Hauling BUA to Port, or Coal Hauling WP to Port.</div>` : ''}<div class="table-responsive"><table class="table-accounting cost-report-table"><thead><tr><th rowspan="2">Account Code</th><th rowspan="2">Account Name</th><th rowspan="2">Check</th><th rowspan="2" class="all-cost-header">All Cost</th><th colspan="6" class="group-header" title="Cost of Goods Produced">COGP (Cost of Goods Produced)</th><th class="cost-group-spacer" rowspan="2" aria-hidden="true"></th><th colspan="6" class="group-header direct-group">Direct Expense</th></tr><tr>${fields.map(([field, label]) => `<th>${esc(label)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody><tfoot><tr><th colspan="3">Grand Total</th>${costValueCell(data.grandTotal.allCost)}${fields.map(([field], index) => `${index === 6 ? '<th class="cost-group-spacer" aria-hidden="true"></th>' : ''}${costValueCell(data.grandTotal[field])}`).join('')}</tr></tfoot></table></div></div>`;
    $('#costPeriod').addEventListener('change', event => { $('#period').value = event.target.value; render(); });
    $('#costClosing').addEventListener('change', async event => {
        const select = event.currentTarget;
        select.disabled = true;
        try {
            const response = await fetch('/api/report-cost-closing', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: JSON.stringify({ period, closed: select.value === 'yes' }) });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload?.message || `Request failed: ${response.status}`);
            await cost(period, mode);
        } catch (error) {
            alert(`Closing status could not be saved: ${error.message}`);
            await cost(period, mode);
        }
    });
    $('#costImportGl').addEventListener('click', () => { state.view = 'integration'; render(); });
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

async function glTransactionReview(period, page = 1) {
    const filters = state.glReview;
    const mode = $('#mode').value;
    const params = new URLSearchParams({ period, mode, page, per_page: filters.rowsPerPage, search: filters.search, date_from: filters.dateFrom, date_to: filters.dateTo, mapping_status: filters.mappingStatus, ...filters.headerFilters, account: filters.headerFilters.account || filters.account, valuation_to: filters.headerFilters.valuation_to || filters.valuationTo, costing_to: filters.headerFilters.costing_to || filters.costingTo });
    const loadingAll = filters.rowsPerPage === 'all';
    if (loadingAll) setGlReviewLoading(true);
    let data;
    try { data = await api(`/api/gl-transaction-review?${params.toString()}`); } finally { if (loadingAll) setGlReviewLoading(false); }
    const summary = data.summary || {};
    const pagination = data.pagination || {};
    const formatReviewNumber = value => value === null || value === undefined || value === '' ? '—' : signedNumber(value, 2);
    const detailFields = [['year_closed', 'Year closed'], ['fuel_quantity', 'Fuel quantity'], ['amount_txn', 'Amount in transaction currency'], ['amount', 'Amount'], ['posting_type', 'Posting type'], ['posting_layer', 'Posting layer'], ['vendor_account', 'Vendor account'], ['customer_account', 'Customer account'], ['invoice_no', 'Invoice no'], ['activity', 'Activity'], ['cash_flow', 'Cash_Flow'], ['department', 'Departmen'], ['group_activity', 'Group_Activity'], ['location', 'Location'], ['project', 'Project'], ['project_name', 'Project name'], ['cost_center', 'Cost center'], ['po_dt_number', 'PO DT Number'], ['gjae_id', 'GJAE ID'], ['created_datetime', 'Created date and time']];
    const detailHtml = row => `<tr id="gl-review-detail-${esc(row.id)}" class="gl-detail-row" hidden><td colspan="12"><div class="gl-detail-grid">${detailFields.map(([field, label]) => `<div><span>${esc(label)}</span><strong>${esc(field === 'fuel_quantity' || field === 'amount_txn' || field === 'amount' || field === 'gjae_id' ? formatReviewNumber(row[field]) : row[field])}</strong></div>`).join('')}</div></td></tr>`;
    const rows = (data.rows || []).map(row => `<tr><td>${esc(formatDate(row.entry_date))}</td><td>${esc(row.main_account)}</td><td>${esc(row.account_name)}</td><td>${esc(row.voucher)}</td><td>${esc(row.journal_number)}</td><td class="review-description">${esc(row.description)}</td><td>${esc(row.costing_to)}</td><td>${esc(row.valuation_to)}</td><td>${esc(row.currency)}</td><td class="num">${row.amount_idr === null || row.amount_idr === undefined ? '—' : `IDR ${formatReviewNumber(row.amount_idr)}`}</td><td class="num">${row.amount_reporting === null || row.amount_reporting === undefined ? '—' : `USD ${formatReviewNumber(row.amount_reporting)}`}</td><td><button type="button" class="btn btn-outline gl-detail-toggle" data-detail-id="gl-review-detail-${esc(row.id)}">View</button></td></tr>${detailHtml(row)}`).join('');
    const voucherSummary = (summary.voucher_summary || []).map(item => `<article><small>${esc(item.code || '—')}</small><strong>${money(item.amount_reporting || 0)}</strong><span>${qty(item.transactions || 0)} of total ${qty(summary.transactions || 0)} transactions</span></article>`).join('');
    const currentPage = pagination.current_page || page;
    const lastPage = pagination.last_page || 1;
    const pageOptions = Array.from({ length: lastPage }, (_, index) => index + 1).map(pageNumber => `<option value="${pageNumber}" ${pageNumber === currentPage ? 'selected' : ''}>${pageNumber}</option>`).join('');
    const pager = `<div class="staging-pager gl-review-pager"><span>Showing ${qty(data.rows?.length || 0)} of total ${qty(pagination.total || 0)} transactions · Page ${currentPage} of ${lastPage}</span><div class="gl-page-controls"><label>Rows <select id="reviewRowsSelect" aria-label="Rows per page"><option value="50" ${filters.rowsPerPage === 50 ? 'selected' : ''}>50</option><option value="100" ${filters.rowsPerPage === 100 ? 'selected' : ''}>100</option><option value="250" ${filters.rowsPerPage === 250 ? 'selected' : ''}>250</option><option value="500" ${filters.rowsPerPage === 500 ? 'selected' : ''}>500</option><option value="1000" ${filters.rowsPerPage === 1000 ? 'selected' : ''}>1000</option><option value="1500" ${filters.rowsPerPage === 1500 ? 'selected' : ''}>1500</option><option value="2000" ${filters.rowsPerPage === 2000 ? 'selected' : ''}>2000</option><option value="all" ${filters.rowsPerPage === 'all' ? 'selected' : ''}>Show all</option></select></label>${currentPage > 1 ? '<button id="reviewPrev" class="btn btn-outline" type="button">Previous</button>' : ''}<label>Page <select id="reviewPageSelect" aria-label="Select page">${pageOptions}</select></label>${currentPage < lastPage ? '<button id="reviewNext" class="btn btn-outline" type="button">Next</button>' : ''}</div></div>`;
    const headerFilterInput = (field, placeholder) => `<input type="search" class="gl-header-filter" data-gl-header-filter="${field}" placeholder="${placeholder}" value="${esc(filters.headerFilters[field] || '')}" aria-label="Filter ${placeholder}">`;
    const rangeLabel = mode === 'ytd' ? `January–${new Date(`${period}-01T00:00:00`).toLocaleString('en-US', { month: 'long' })} ${period.slice(0, 4)}` : period;
    $('#pageContent').innerHTML = `${sectionHeader('GL Transaction Review', `Imported D365 GL transactions for ${rangeLabel}. Amounts use reporting currency (USD).`, '<button id="glReviewExport" class="btn btn-outline" type="button">📥 Export Excel</button>')}<form id="glReviewFilters" class="gl-review-filters"><label>Account<input type="search" name="account" placeholder="Type account code or name" value="${esc(filters.account)}"></label><label>Search<input type="search" name="search" placeholder="Voucher, description, location..." value="${esc(filters.search)}"></label><label>Date From<input type="date" name="date_from" value="${esc(filters.dateFrom)}"></label><label>Date To<input type="date" name="date_to" value="${esc(filters.dateTo)}"></label><label>Mapping Status<select name="mapping_status"><option value="all" ${filters.mappingStatus === 'all' ? 'selected' : ''}>All</option><option value="mapped" ${filters.mappingStatus === 'mapped' ? 'selected' : ''}>Mapped</option><option value="unmapped" ${filters.mappingStatus === 'unmapped' ? 'selected' : ''}>Unmapped</option></select></label><label>Valuation To<input type="search" name="valuation_to" placeholder="e.g. Mining" value="${esc(filters.valuationTo)}"></label><label>Costing To<input type="search" name="costing_to" placeholder="e.g. Direct Expense" value="${esc(filters.costingTo)}"></label><button class="btn btn-primary" type="submit">Refresh</button><button id="clearGlReviewFilters" class="btn btn-outline" type="button">Clear filters</button></form><div class="financial-notice">Imported GL only. Historical cost summaries, dashboard accruals, and other adjustments are not individual GL transactions and are excluded.</div><div class="financial-notice">Source: <b>gl_entries</b>. Formula: rows = filtered D365 GL transactions; amount = GL amount in reporting currency.</div><section class="voucher-summary"><div class="voucher-summary-heading"><div><h3>Voucher Summary</h3><p>Grouped by the first 3 characters of voucher · filtered transactions · reporting currency (USD)</p></div><button id="voucherSummaryToggle" class="voucher-summary-toggle" type="button" aria-controls="voucherSummaryGrid" aria-expanded="${!state.voucherSummaryCollapsed}">${state.voucherSummaryCollapsed ? 'Show summary' : 'Collapse'} <span aria-hidden="true">${state.voucherSummaryCollapsed ? '⌄' : '⌃'}</span></button></div><div id="voucherSummaryGrid" class="voucher-summary-grid" ${state.voucherSummaryCollapsed ? 'hidden' : ''}>${voucherSummary || '<p class="integration-note">No voucher summary for the selected filters.</p>'}</div></section>${card(`${badge('gl_entries')} ${qty(pagination.total || 0)} transactions`, `<div class="table-responsive gl-review-table"><table class="table-accounting"><thead><tr><th>Date</th><th>${headerFilterInput('account', 'Account')}</th><th>${headerFilterInput('account_name', 'Account Name')}</th><th>${headerFilterInput('voucher', 'Voucher')}</th><th>${headerFilterInput('journal_number', 'Journal')}</th><th>${headerFilterInput('description', 'Description')}</th><th>${headerFilterInput('costing_to', 'Costing To')}</th><th>${headerFilterInput('valuation_to', 'Valuation To')}</th><th>${headerFilterInput('currency', 'Currency')}</th><th>Amount (IDR)</th><th>Amount (USD)</th><th>Details</th></tr></thead><tbody>${rows || `<tr><td colspan="12">No D365 GL transactions found for the selected filters.</td></tr>`}</tbody></table></div>${pager}`)}`;
    $('#voucherSummaryToggle').addEventListener('click', event => {
        state.voucherSummaryCollapsed = !state.voucherSummaryCollapsed;
        const collapsed = state.voucherSummaryCollapsed;
        $('#voucherSummaryGrid').hidden = collapsed;
        event.currentTarget.setAttribute('aria-expanded', String(!collapsed));
        event.currentTarget.innerHTML = `${collapsed ? 'Show summary' : 'Collapse'} <span aria-hidden="true">${collapsed ? '⌄' : '⌃'}</span>`;
    });
    $('#glReviewFilters').addEventListener('submit', event => { event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); state.glReview = { account: values.get('account') || '', search: values.get('search') || '', dateFrom: values.get('date_from') || '', dateTo: values.get('date_to') || '', rowsPerPage: filters.rowsPerPage, mappingStatus: values.get('mapping_status') || 'all', valuationTo: values.get('valuation_to') || '', costingTo: values.get('costing_to') || '', headerFilters: filters.headerFilters }; glTransactionReview(period); });
    $('#clearGlReviewFilters').addEventListener('click', () => { state.glReview = { account: '', search: '', dateFrom: '', dateTo: '', rowsPerPage: 100, mappingStatus: 'all', valuationTo: '', costingTo: '', headerFilters: { account: '', account_name: '', voucher: '', journal_number: '', description: '', costing_to: '', valuation_to: '', currency: '' } }; glTransactionReview(period); });
    $('#glReviewExport').addEventListener('click', () => downloadExcel(`gl-transaction-review-${period}-${mode}.xls`, ['Date', 'Account', 'Account Name', 'Voucher', 'Journal', 'Description', 'Costing To', 'Valuation To', 'Currency', 'Amount (IDR)', 'Amount (USD)'], (data.rows || []).map(row => [row.entry_date, row.main_account, row.account_name, row.voucher, row.journal_number, row.description, row.costing_to, row.valuation_to, row.currency, row.amount_idr, row.amount_reporting])));
    $('#reviewPrev')?.addEventListener('click', () => glTransactionReview(period, page - 1));
    $('#reviewNext')?.addEventListener('click', () => glTransactionReview(period, page + 1));
    $('#reviewPageSelect')?.addEventListener('change', event => glTransactionReview(period, Number(event.currentTarget.value)));
    $('#reviewRowsSelect')?.addEventListener('change', event => { filters.rowsPerPage = event.currentTarget.value === 'all' ? 'all' : Number(event.currentTarget.value); glTransactionReview(period, 1); });
    document.querySelectorAll('.gl-detail-toggle').forEach(button => button.addEventListener('click', () => { const detail = document.getElementById(button.dataset.detailId); if (!detail) return; detail.hidden = !detail.hidden; button.textContent = detail.hidden ? 'View' : 'Hide'; }));
    document.querySelectorAll('[data-gl-header-filter]').forEach(input => { const apply = () => { filters.headerFilters[input.dataset.glHeaderFilter] = input.value; glTransactionReview(period); }; input.addEventListener('change', apply); input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); apply(); } }); });
}

async function variance(period, mode) {
    const data = await api(`/api/cost-variance?period=${period}&mode=${mode}&location=${encodeURIComponent(state.varianceLocation)}`);
    const varianceOptions = [['allCost', 'ALL Cost'], ['costMining', 'COGP - Mining'], ['costHaulingBUAToWP', 'COGP - Coal Hauling BUA to WP'], ['costHaulingBUAToPort', 'COGP - Coal Hauling BUA to Port'], ['costHaulingWPToPort', 'COGP - Coal Hauling WP to Port'], ['costWP', 'COGP - Washing Plant'], ['costPort', 'COGP - BP PORT'], ['valMining', 'Direct Expense - Mining'], ['valHauling', 'Direct Expense - Coal Hauling'], ['valWP', 'Direct Expense - Washing Plant'], ['valPort', 'Direct Expense - BP PORT'], ['directGA', 'Direct Expense - GA'], ['sellingExpense', 'Selling Expense']];
    const renderVariance = () => {
        const search = state.varianceSearch.trim().toLowerCase();
        const minimum = Math.max(0, Number(state.varianceMinimum) || 0);
        const visibleRows = data.rows.filter(row => {
            const matchesSearch = !search || `${row.code} ${row.name}`.toLowerCase().includes(search);
            const matchesZero = !state.varianceHideZero || row.previous == null || row.current == null || Number(row.previous) !== 0 || Number(row.current) !== 0;
            const matchesMinimum = minimum === 0 || (row.change != null && Math.abs(Number(row.change)) >= minimum);
            return matchesSearch && matchesZero && matchesMinimum;
        });
        if (state.varianceOrder === 'largest') visibleRows.sort((a, b) => Math.abs(Number(b.change) || 0) - Math.abs(Number(a.change) || 0));
        const fmt = value => value == null ? '—' : reportAmount(value);
        const rowHtml = visibleRows.map(row => { const direction = row.changePct > 0 ? `<span class="variance-arrow positive" aria-label="Increase">↑</span> ` : row.changePct < 0 ? `<span class="variance-arrow negative" aria-label="Decrease">↓</span> ` : ''; return `<tr class="${row.isParent ? 'parent-row' : 'child-row'}"><td>${esc(row.code)}</td><td>${esc(row.name)}</td><td class="num">${fmt(row.previous)}</td><td class="num">${fmt(row.current)}</td><td class="num ${row.change < 0 ? 'negative' : row.change > 0 ? 'positive' : ''}">${fmt(row.change)}</td><td class="num">${row.changePct == null ? '—' : `${direction}${signedNumber(row.changePct, 3, 3, '%')}`}</td><td><div class="variance-remark-editor"><textarea class="remark-input" rows="2" maxlength="2000" data-variance-remark="${esc(row.code)}" placeholder="Add explanation...">${esc(row.remark || '')}</textarea><button class="btn btn-outline variance-remark-save" type="button" data-variance-save="${esc(row.code)}">Save</button><span class="variance-remark-status" data-variance-status="${esc(row.code)}" role="status" aria-live="polite"></span></div></td></tr>`; });
        const hasPrevious = data.hasPrevious ?? (data.previous?.dataSource !== 'none');
        const hasCurrent = data.hasCurrent ?? (data.current?.dataSource !== 'none');
        const previousTotal = hasPrevious ? Number(data.previous?.grandTotal?.[state.varianceLocation] ?? 0) : null;
        const currentTotal = hasCurrent ? Number(data.current?.grandTotal?.[state.varianceLocation] ?? 0) : null;
        const totalChange = previousTotal == null || currentTotal == null ? null : currentTotal - previousTotal;
        const totalPercent = previousTotal == null || previousTotal === 0 || totalChange == null ? null : totalChange / Math.abs(previousTotal) * 100;
        const sourceName = matrix => matrix?.dataSource === 'none' ? 'no data' : matrix?.period >= '2026-10' && matrix?.dataSource === 'gl' ? 'D365 synced GL' : matrix?.dataSource === 'workbook' ? 'historical baseline' : matrix?.dataSource || 'unavailable';
        const controls = `<div class="report-source-row">${badge(period >= '2026-10' ? (mode === 'ytd' ? 'Oct onward: D365 GL · Jan–Sep: baseline' : 'D365 GL sync') : 'Historical baseline')} ${badge('Database: cost_variance_remarks')}</div><div class="variance-controls"><label>Current month <input id="variancePeriod" type="month" value="${period}"></label><label>Location / category <select id="varianceLocation">${varianceOptions.map(([value, label]) => `<option value="${value}" ${state.varianceLocation === value ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label><label>Search account <input id="varianceSearch" type="search" placeholder="Account code or name" value="${esc(state.varianceSearch)}"></label><label>Minimum change (USD) <input id="varianceMinimum" type="number" min="0" step="1" value="${esc(state.varianceMinimum)}"></label><label>Order <select id="varianceOrder"><option value="account" ${state.varianceOrder === 'account' ? 'selected' : ''}>Account order</option><option value="largest" ${state.varianceOrder === 'largest' ? 'selected' : ''}>Largest change first</option></select></label><label class="variance-checkbox"><input id="varianceHideZero" type="checkbox" ${state.varianceHideZero ? 'checked' : ''}> Hide zero balances</label></div><p class="variance-note">Monthly MTD comparison: ${esc(data.period)} vs ${esc(data.previousPeriod)}. ${esc(data.previousPeriod)}: ${esc(sourceName(data.previous))}; ${esc(data.period)}: ${esc(sourceName(data.current))}. Global MTD/YTD selection does not change this month-to-month comparison. Variance = current − previous; % uses the absolute previous balance. Missing data is shown as —.</p><p class="table-note">${visibleRows.length} rows • MTD comparison ${data.previousPeriod} → ${data.period}</p>`;
        const varianceTable = `<div class="table-responsive"><table class="table-accounting variance-table"><thead><tr>${['Account', 'Account Name', 'Previous month', 'Current month', 'Variance USD', 'Variance %', 'Remark'].map(header => `<th>${esc(header)}</th>`).join('')}</tr></thead><tbody>${rowHtml.length ? rowHtml.join('') : `<tr><td colspan="7">No data available.</td></tr>`}</tbody><tfoot><tr><th colspan="2">Total</th><th class="num">${fmt(previousTotal)}</th><th class="num">${fmt(currentTotal)}</th><th class="num ${totalChange < 0 ? 'negative' : totalChange > 0 ? 'positive' : ''}">${fmt(totalChange)}</th><th class="num">${totalPercent == null ? '—' : signedNumber(totalPercent, 3, 3, '%')}</th><th></th></tr></tfoot></table></div>`;
        $('#pageContent').innerHTML = `${sectionHeader('Cost Variance Analysis', 'Compare monthly cost by account and location; record explanations for each variance.', `<button id="varianceExcel" class="btn btn-outline" type="button">📊 Export Excel</button>`)}${card(controls, varianceTable)}`;
        $('#variancePeriod').addEventListener('change', event => { $('#period').value = event.target.value; render(); });
        $('#varianceSearch').addEventListener('input', event => { state.varianceSearch = event.target.value; const cursor = event.target.selectionStart; renderVariance(); const input = $('#varianceSearch'); input?.focus(); input?.setSelectionRange(cursor, cursor); });
        $('#varianceMinimum').addEventListener('change', event => { state.varianceMinimum = event.target.value; renderVariance(); });
        $('#varianceOrder').addEventListener('change', event => { state.varianceOrder = event.target.value; renderVariance(); });
        $('#varianceHideZero').addEventListener('change', event => { state.varianceHideZero = event.target.checked; renderVariance(); });
        $('#varianceLocation').addEventListener('change', event => { state.varianceLocation = event.target.value; variance(period, mode); });
        $('#varianceExcel').addEventListener('click', () => downloadExcel(`cost-variance-${period}-mtd.xls`, ['Account', 'Account Name', 'Previous month', 'Current month', 'Variance USD', 'Variance %', 'Remark'], visibleRows.map(row => [row.code, row.name, row.previous, row.current, row.change, row.changePct == null ? '' : row.changePct, row.remark || ''])));
        document.querySelectorAll('[data-variance-save]').forEach(button => button.addEventListener('click', async event => {
            const saveButton = event.currentTarget;
            const accountCode = saveButton.dataset.varianceSave;
            const editor = saveButton.closest('.variance-remark-editor');
            const input = editor.querySelector('.remark-input');
            const status = editor.querySelector('.variance-remark-status');
            saveButton.disabled = true;
            status.textContent = 'Saving…';
            status.classList.remove('is-error');
            try {
                await postJson('/api/cost-variance/remark', { period, category: state.varianceLocation, account_code: accountCode, remark: input.value });
                const target = data.rows.find(item => String(item.code) === accountCode);
                if (target) target.remark = input.value;
                status.textContent = 'Saved';
            } catch (error) {
                status.textContent = `Save failed: ${error.message}`;
                status.classList.add('is-error');
            } finally {
                saveButton.disabled = false;
            }
        }));
    };
    renderVariance();
}
async function metric(period, mode) {
    const previousPeriod = (() => { const date = new Date(`${period}-01T00:00:00Z`); date.setUTCMonth(date.getUTCMonth() - 1); return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`; })();
    const [data, dbSummary, excel, previousExcel, production, previousProduction] = await Promise.all([api(`/api/cost-per-mt?period=${period}&mode=${mode}`), api(`/api/db-cost-summary?period=${period}&mode=${mode}`), api(`/api/excel-cost-example?period=${period}`), period >= '2026-10' && previousPeriod < '2026-10' ? api(`/api/excel-cost-example?period=${previousPeriod}`) : Promise.resolve(null), api(`/api/production-summary?period=${period}&mode=mtd`), api(`/api/production-summary?period=${previousPeriod}&mode=mtd`)]);
    const metricRate = value => value == null ? 'USD —' : `USD ${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const metricVolume = value => value == null ? '—' : `${Math.round(Number(value)).toLocaleString('en-US')} MT`;
    const stageCards = data.stages.map(stage => `<article class="stage-card"><span>${esc(stage.stage)}</span><strong>${metricRate(stage.costPerMT)}</strong><small>per stage MT (${metricVolume(stage.metricTons)})</small><em>Total Cost: ${money(stage.totalCost)} (USD)</em></article>`).join('');
    const rows = data.stages.map(stage => `<tr><td><strong>${esc(stage.stage)}</strong></td><td class="num">${reportAmount(stage.valuationCost)}</td><td class="num">${reportAmount(stage.operatingCost)}</td><td class="num">${reportAmount(stage.totalCost)}</td><td class="num">${metricVolume(stage.metricTons)}</td><td class="num stage-rate">${metricRate(stage.costPerMT)}</td><td class="num">${metricRate(stage.costPerTotalMT)}</td></tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Cost per Metric Ton Analysis', 'D365 accounting costs and Google Sheets weighbridge volume')}
        <p class="metric-notice">Stage rates use Google Sheets monthly volumes: Coal Getting, the three hauling routes, Washing Plant Feed, and Port Loading. Missing volumes are unavailable. Monthly Cost Comparison always uses MTD values, regardless of the global MTD/YTD selection.</p>
        <p class="source-row">${period >= '2026-10' ? (mode === 'ytd' ? `${badge('D365 GL · Oct onward')} ${badge('Google Sheets DB sync')} ${badge('Historical baseline · Jan–Sep')}` : `${badge('D365 GL Sync')} ${badge('Google Sheets · DB Sync')}`) : `${badge('Historical baseline')} ${badge('Google Sheets · DB Sync')}`}</p>
        <div class="cost-stage-grid">${stageCards}</div>
        ${card(`Coal Mining Cost by Stage ${badge(period >= '2026-10' ? 'D365 GL sync' : 'Historical baseline')} ${badge('Google Sheets production sync')}`, table(['Mining Activity', 'From Valuation to (USD)', 'From Costing to (USD)', 'Total Cost (USD)', 'Operating Volume (MT)', 'Stage Cost / MT', 'Cost / MT Total Production'], rows))}
        ${monthlyPerformance(period, excel, dbSummary, { [period]: production, [previousPeriod]: previousProduction }, previousExcel)}
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
            const color = percent < 0 ? '#dc2626' : percent > 0 ? '#15803d' : '';
            return `<td class="num">${Number.isFinite(value) ? signedNumber(value, 0) : '—'}</td>${i ? `<td class="num"${color ? ` style="color:${color};font-weight:700"` : ''}>${percent === null ? '—' : signedNumber(percent, 3, 3, '%')}</td>` : ''}`;
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
            const response = await fetch('/api/db-cost-summary/remark', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: row.id, period: summary.period, remark: input.value }) });
            if (!response.ok) throw new Error('Save failed');
            summary.remarks = summary.remarks || {};
            summary.remarks[row.id] = input.value;
            button.textContent = 'Saved';
        } catch (error) { button.textContent = 'Retry'; } finally { button.disabled = false; }
    };
}

function monthlyPerformance(period, excel, dashboardSummary = null, productionByPeriod = {}, previousExcel = null) {
    const date = new Date(`${period}-01T00:00:00Z`);
    const previousDate = new Date(date);
    previousDate.setUTCMonth(previousDate.getUTCMonth() - 1);
    const monthLabel = value => value.toLocaleDateString('en-US', { month: 'short', year: '2-digit', timeZone: 'UTC' }).replace(',', '');
    const currentMonth = date.toLocaleDateString('en-US', { month: 'short', timeZone: 'UTC' });
    const previousPeriod = `${previousDate.getUTCFullYear()}-${String(previousDate.getUTCMonth() + 1).padStart(2, '0')}`;
    const abcMonthly = (period >= '2026-10' ? [] : (excel?.abcMonthly || [])).map(row => ({ ...row }));
    const previousAbcMonthly = ((previousPeriod < '2026-10' ? (previousExcel?.abcMonthly || excel?.abcMonthly) : excel?.abcMonthly) || []).map(row => ({ ...row }));
    const cogpMonthly = (previousPeriod < '2026-10' ? (previousExcel?.cogpMonthly || excel?.cogpMonthly) : excel?.cogpMonthly) || [];
    const previousMonth = previousDate.toLocaleDateString('en-US', { month: 'short', timeZone: 'UTC' });
    const rowsFor = (source, month) => source.filter(row => String(row.month).toLowerCase() === month.toLowerCase());
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
    const current = aggregate(rowsFor(abcMonthly, currentMonth), row => row.activity);
    const previous = aggregate(rowsFor(previousAbcMonthly, previousMonth), row => row.activity);
    const rate = item => item?.hasQty && item?.hasCost && item.qty > 0 ? item.cost / item.qty : null;
    const calc = (item, old) => {
        const currentRate = rate(item), previousRate = rate(old), change = currentRate !== null && previousRate !== null ? currentRate - previousRate : null;
        return [currentRate, previousRate, change, change !== null && previousRate !== 0 ? change / Math.abs(previousRate) : null];
    };
    const formatCell = (value, percent = false, whole = false) => {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        if (whole) return signedNumber(value, 0);
        return percent ? signedNumber(Number(value) * 100, 3, 3, '%') : detailMoney(value);
    };
    const movementRows = [...new Set([...current.keys(), ...previous.keys()])].map(key => {
        const item = current.get(key) || previous.get(key);
        const values = calc(current.get(key), previous.get(key));
        return `<tr><td>${esc(item.activity)}</td><td class="num">${formatCell(item.hasQty ? item.qty : null, false, true)}</td><td class="num">${formatCell(item.hasCost ? item.cost : null, false, true)}</td><td class="num">${formatCell(values[0])}</td><td class="num">${formatCell(values[1])}</td><td class="num ${values[2] < 0 ? 'negative' : values[2] > 0 ? 'positive' : ''}">${formatCell(values[2])}</td><td class="num ${values[3] < 0 ? 'negative' : values[3] > 0 ? 'positive' : ''}">${formatCell(values[3], true)}</td><td>—</td></tr>`;
    });
    const counterpartCurrent = aggregate(rowsFor(abcMonthly, currentMonth), row => JSON.stringify([row.activity, row.entity]));
    const counterpartPrevious = aggregate(rowsFor(previousAbcMonthly, previousMonth), row => JSON.stringify([row.activity, row.entity]));
    const counterpartRows = [...new Set([...counterpartCurrent.keys(), ...counterpartPrevious.keys()])].map(key => {
        const item = counterpartCurrent.get(key) || counterpartPrevious.get(key), values = calc(counterpartCurrent.get(key), counterpartPrevious.get(key));
        return `<tr><td>${esc(item.activity)}</td><td>${esc(item.entity)}</td><td class="num">${formatCell(values[0])}</td><td class="num">${formatCell(values[1])}</td><td class="num ${values[2] < 0 ? 'negative' : values[2] > 0 ? 'positive' : ''}">${formatCell(values[2])}</td><td class="num ${values[3] < 0 ? 'negative' : values[3] > 0 ? 'positive' : ''}">${formatCell(values[3], true)}</td><td>—</td></tr>`;
    });
    const volumeKeys = { 'Topsoil': 'topsoil', 'Mud': 'mud', 'Coal Getting': 'coalGetting', 'Coal Hauling Overdistance': 'coalGetting', 'Coal Loading at ROM to Port': 'loadingBuaPort', 'Coal Loading at ROM to WP': 'loadingBuaWp', 'Coal Loading at WP to Port': 'loadingWpPort', 'Coal Hauling ROM BUA to Port': 'haulingBuaPort', 'Coal Hauling ROM BUA to WP': 'haulingBuaWp', 'Coal Hauling WP to Port': 'haulingWpPort', 'Washplant': 'washCoal', 'Loading BLC': 'portLoading', 'ISP Rent': 'portToIsp', 'Transhipmet BP Port To Taboneo': 'portToTaboneo', 'Transhipmet BP Port To ISP': 'portToIsp', 'Transhipmet ISP to Taboneo': 'ispToTaboneo', 'Overhead BUA': 'coalGetting', 'OVH JKT & PC': 'coalGetting' };
    const applyDashboardSource = (targetPeriod, activities, entities) => {
        if (targetPeriod < '2026-10' || !dashboardSummary) return;
        const metrics = productionByPeriod[targetPeriod]?.dbMetrics || {};
        const grouped = new Map();
        (dashboardSummary.rows || []).forEach(row => {
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
        const entityVolumes = new Map();
        (dashboardSummary.entityVolumes || []).forEach(row => {
            const value = row.months?.[targetPeriod];
            if (Number.isFinite(Number(value))) entityVolumes.set(JSON.stringify([row.activity, row.entity]), Number(value));
        });
        entityVolumes.forEach((volume, key) => {
            const item = entities.get(key);
            if (item) { item.qty = volume; item.hasQty = true; }
        });
        activityTotals.forEach((total, name) => {
            const existing = activities.get(name) || { activity: name, qty: 0, hasQty: false };
            existing.cost = total.cost; existing.hasCost = true;
            const rawVolume = metrics[volumeKeys[name]]?.value;
            let volume = rawVolume == null || rawVolume === '' ? NaN : Number(rawVolume);
            if (name === 'OB Removal') { const rawBlasting = metrics.blasting?.value, rawFreedig = metrics.freedig?.value; const blasting = rawBlasting == null || rawBlasting === '' ? NaN : Number(rawBlasting), freedig = rawFreedig == null || rawFreedig === '' ? NaN : Number(rawFreedig); volume = Number.isFinite(blasting) && Number.isFinite(freedig) ? blasting + freedig : NaN; }
            existing.qty = Number.isFinite(volume) ? volume : 0; existing.hasQty = Number.isFinite(volume); activities.set(name, existing);
            if (total.entities === 1 && existing.hasQty) entities.forEach(item => { if (item.activity === name && !item.hasQty) { item.qty = volume; item.hasQty = true; } });
        });
    };
    applyDashboardSource(period, current, counterpartCurrent);
    applyDashboardSource(previousPeriod, previous, counterpartPrevious);
    const dashboardMovementRows = [...new Set([...current.keys(), ...previous.keys()])].map(key => {
        const item = current.get(key) || previous.get(key); const values = calc(current.get(key), previous.get(key));
        return `<tr><td>${esc(item.activity)}</td><td class="num">${formatCell(item.hasQty ? item.qty : null, false, true)}</td><td class="num">${formatCell(item.hasCost ? item.cost : null, false, true)}</td><td class="num">${formatCell(values[0])}</td><td class="num">${formatCell(values[1])}</td><td class="num ${values[2] < 0 ? 'negative' : values[2] > 0 ? 'positive' : ''}">${formatCell(values[2])}</td><td class="num ${values[3] < 0 ? 'negative' : values[3] > 0 ? 'positive' : ''}">${formatCell(values[3], true)}</td><td>Google Sheets DB sync + D365 GL</td></tr>`;
    });
    const dashboardCounterpartRows = [...new Set([...counterpartCurrent.keys(), ...counterpartPrevious.keys()])].map(key => {
        const item = counterpartCurrent.get(key) || counterpartPrevious.get(key); const values = calc(counterpartCurrent.get(key), counterpartPrevious.get(key));
        return `<tr><td>${esc(item.activity)}</td><td>${esc(item.entity)}</td><td class="num">${formatCell(values[0])}</td><td class="num">${formatCell(values[1])}</td><td class="num ${values[2] < 0 ? 'negative' : values[2] > 0 ? 'positive' : ''}">${formatCell(values[2])}</td><td class="num ${values[3] < 0 ? 'negative' : values[3] > 0 ? 'positive' : ''}">${formatCell(values[3], true)}</td><td>Google Sheets DB sync + D365 GL</td></tr>`;
    });
    const monthIndex = date.getUTCMonth(), previousIndex = previousDate.getUTCMonth();
    const cogpRows = cogpMonthly.map(row => {
        const currentValue = period >= '2026-10' && Number(row.months?.[monthIndex]) === 0 ? null : row.months?.[monthIndex];
        const previousValue = previousPeriod >= '2026-10' && Number(row.months?.[previousIndex]) === 0 ? null : row.months?.[previousIndex];
        const hasCurrent = currentValue !== null && currentValue !== undefined && currentValue !== '' && Number.isFinite(Number(currentValue));
        const hasPrevious = previousValue !== null && previousValue !== undefined && previousValue !== '' && Number.isFinite(Number(previousValue));
        const change = hasCurrent && hasPrevious ? Number(currentValue) - Number(previousValue) : null;
        const percent = change !== null && Number(previousValue) !== 0 ? change / Math.abs(Number(previousValue)) : null;
        return `<tr><td>${esc(row.label)}</td><td class="num">${formatCell(currentValue)}</td><td class="num">${formatCell(previousValue)}</td><td class="num ${change < 0 ? 'negative' : change > 0 ? 'positive' : ''}">${formatCell(change)}</td><td class="num ${percent < 0 ? 'negative' : percent > 0 ? 'positive' : ''}">${formatCell(percent, true)}</td></tr>`;
    });
    const useDashboardSource = period >= '2026-10' && Boolean(dashboardSummary?.rows?.length);
    const hasBaselineComparison = previousPeriod < '2026-10' && Boolean(previousExcel?.abcMonthly?.length);
    const status = useDashboardSource
        ? `${monthLabel(date)} vs ${monthLabel(previousDate)}. Current activity costs and volumes use D365 GL and Google Sheets sync; ${hasBaselineComparison ? 'previous-month activity rates use the approved January–September historical baseline.' : 'previous-month values are shown when a synced source is available.'} Counterpart rates use entity-specific Google Sheets volumes. October COGP monthly rates are unavailable without a synced COGP monthly source.`
        : excel?.abcMonthly && excel?.cogpMonthly ? `${monthLabel(date)} vs ${monthLabel(previousDate)}. Activity-Based Cost and Counterpart rates use the historical spreadsheet snapshot. Red = increase, green = decrease.` : 'Monthly data unavailable. Sync Google Sheets production data.';
    return `<section class="table-card cost-analysis-card monthly-performance"><div class="cost-analysis-heading"><div><span class="cost-eyebrow">MONTHLY PERFORMANCE</span><h3>Monthly Cost Comparison</h3><p>Compare activity costs with the previous month.</p></div><label class="cost-period-control">Comparison month <input type="month" value="${esc(period)}" disabled></label></div><p id="excel-example-status">${esc(status)}</p><h4 class="cost-subheading">COGP Comparison <span>Production cost per metric ton</span></h4>${table(['Location', 'Current USD/MT', 'Previous USD/MT', 'Change USD/MT', 'Change %'], cogpRows)}<h4 class="cost-subheading">Activity-Based Cost <span>Volume, cost and monthly movement</span></h4>${table(['Activity', 'Qty', 'Cost USD', 'Current USD/Unit', 'Previous USD/Unit', 'Change USD/Unit', 'Change %', 'Remarks'], useDashboardSource ? dashboardMovementRows : movementRows)}<h4 class="cost-subheading">Activity-Based Cost by Counterpart <span>Breakdown by entity</span></h4>${table(['Activity', 'Counterpart', 'Current USD/Unit', 'Previous USD/Unit', 'Change USD/Unit', 'Change %', 'Remarks'], useDashboardSource ? dashboardCounterpartRows : counterpartRows)}</section>`;
}

async function inventory(period, mode) {
    const data = await api(`/api/inventory-valuation?period=${period}&mode=${mode}`);
    const manualInputs = await api(`/api/inventory-inputs?period=${encodeURIComponent(period)}&mode=${encodeURIComponent(mode)}`);
    const stages = data.stages || [];
    const number = value => value == null || !Number.isFinite(Number(value)) ? '—' : reportAmount(value);
    const volume = value => value == null || !Number.isFinite(Number(value)) ? '—' : signedNumber(value, 0);
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
    warnings.push(...(data.validationWarnings || []));
    warnings.push(...(data.historyIssues || data.issues || []).slice(0, 5));
    const renderLocationCard = stage => `<article class="inventory-location-card"><header class="inventory-location-heading"><div><span class="inventory-card-eyebrow">STOCK LOCATION</span><h3>${esc(stage.name)}</h3></div><span class="inventory-location-badge">${esc(mode.toUpperCase())} · ${esc(data.periodKey || period)}</span></header><div class="inventory-location-lines"><div><span>Opening balance</span><span>${volume(stage.beginningQtyMT)} MT · USD ${number(stage.beginningValueUSD)}</span></div><div><span>Inflow</span><strong>${volume(stage.inflowQtyMT)} MT</strong></div><div><span>Inflow value</span><span>USD ${number(stage.receiptValueUSD)}</span></div><div><span>Add cost</span><span>USD ${number(stage.addedCostUSD)}</span></div><div><span>Outflow</span><span>${volume(stage.outflowQtyMT)} MT</span></div><div><span>Adjustments</span><span>${volume(stage.adjustmentQtyMT)} MT · USD ${number(stage.adjustmentValueUSD)}</span></div><div class="inventory-key-metric"><span>Closing quantity</span><strong>${volume(stage.endingQtyMT)} MT</strong></div><div><span>Closing rate</span><strong>USD ${number(stage.endingRateUSD)} / MT</strong></div></div><div class="inventory-location-total"><span>Inventory value</span><strong>USD ${number(stage.endingValueUSD)}</strong></div></article>`;
    const washingIds = ['nonWash', 'washed', 'highAsh'];
    const washingNames = { nonWash: 'Non Wash', washed: 'Wash', highAsh: 'Reject (High Ash)' };
    const washing = washingIds.map(id => stages.find(stage => stage.id === id)).filter(Boolean);
    const washingCard = `<details class="inventory-washing-card"><summary><header class="inventory-location-heading"><div><span class="inventory-card-eyebrow">LOCATION GROUP</span><h3>Washing Plant</h3><p>Select to view location details</p></div><span class="inventory-location-badge">${esc(mode.toUpperCase())} · ${esc(data.periodKey || period)}</span></header><div class="inventory-washing-summary">${washing.map(stage => `<div><span>${washingNames[stage.id]}</span><strong>${volume(stage.endingQtyMT)} MT</strong><small>USD ${number(stage.endingValueUSD)} · USD ${number(stage.endingRateUSD)}/MT</small></div>`).join('')}</div><div class="inventory-location-total"><span>Total stock</span><strong>${volume(washing.reduce((sum, stage) => sum + Number(stage.endingQtyMT || 0), 0))} MT</strong></div></summary><div class="inventory-washing-details">${washing.map(renderLocationCard).join('')}</div></details>`;
    const locationCards = stages.map(stage => stage.id === 'nonWash' ? washingCard : washingIds.includes(stage.id) ? '' : renderLocationCard(stage)).join('');
    const totalQty = Number(data.totalEndingQtyMT ?? stages.reduce((total, stage) => total + Number(stage.endingQtyMT || 0), 0));
    const totalValue = Number(data.totalEndingValueUSD ?? stages.reduce((total, stage) => total + Number(stage.endingValueUSD || 0), 0));
    const colors = ['#2563eb', '#0891b2', '#7c3aed', '#d97706', '#0f766e', '#ea580c', '#64748b', '#e11d48', '#0e7490', '#4f46e5', '#65a30d'];
    const includedStages = stages.filter(stage => stage.includedInQuantityTotal !== false);
    const totalIncludedQty = includedStages.reduce((sum, stage) => sum + Number(stage.endingQtyMT || 0), 0);
    const positiveStages = includedStages.filter(stage => Number(stage.endingQtyMT) > 0);
    const positiveTotalQty = positiveStages.reduce((sum, stage) => sum + Number(stage.endingQtyMT || 0), 0);
    const negativeStages = includedStages.filter(stage => Number(stage.endingQtyMT) < 0);
    const chartCanDraw = positiveTotalQty > 0;
    const share = stage => {
        if (stage.includedInQuantityTotal === false) return 'Excluded';
        if (Number(stage.endingQtyMT) < 0) return 'Negative';
        if (!chartCanDraw) return '—';
        const percent = Number(stage.endingQtyMT) / positiveTotalQty * 100;
        return percent < 0.5 ? '<1%' : signedNumber(percent, 0, 0, '%');
    };
    let offset = 0;
    const circumference = 2 * Math.PI * 108;
    const segments = positiveStages.map(stage => {
        const index = stages.indexOf(stage);
        const length = Number(stage.endingQtyMT) / positiveTotalQty * circumference;
        const segment = `<circle class="inventory-donut-segment" data-stock-id="${esc(stage.id)}" tabindex="0" role="img" aria-label="${esc(stage.name)}, ${volume(stage.endingQtyMT)} MT, ${share(stage)}, USD ${number(stage.endingValueUSD)}" cx="140" cy="140" r="108" fill="none" stroke="${colors[index % colors.length]}" stroke-width="32" stroke-dasharray="${Math.max(0,length-3)} ${circumference-length+3}" stroke-dashoffset="${-offset}" transform="rotate(-90 140 140)"><title>${esc(stage.name)} · ${volume(stage.endingQtyMT)} MT · ${share(stage)} · USD ${number(stage.endingValueUSD)}</title></circle>`;
        offset += length;
        return segment;
    }).join('');
    const donutCenter = `<text id="inventoryDonutCenterLabel" x="140" y="113" class="inventory-donut-caption">CLOSING STOCK VALUE</text><text id="inventoryDonutCenterValue" x="140" y="143" class="inventory-donut-amount">USD ${number(totalValue)}</text><text id="inventoryDonutCenterVolume" x="140" y="164" class="inventory-donut-caption">Net ${volume(totalIncludedQty)} MT</text><text id="inventoryDonutCenterShare" x="140" y="184" class="inventory-donut-center-hint">Hover or select a location</text>`;
    const negativeNote = negativeStages.length ? `<div class="inventory-chart-alert"><strong>Negative stock is excluded from the slices</strong><span>${negativeStages.map(stage => `${esc(stage.name)}: ${volume(stage.endingQtyMT)} MT`).join(' · ')}</span></div>` : '';
    const donut = `<div class="inventory-donut-layout" data-positive-total="${positiveTotalQty}"><div class="inventory-donut-visual"><svg viewBox="0 0 280 280" class="inventory-donut" role="img" aria-label="Coal stock composition by positive closing tonnage, ${esc(period)}"><circle class="inventory-donut-track" cx="140" cy="140" r="108" fill="none" stroke="#e2e8f0" stroke-width="32"/>${segments}${donutCenter}</svg><div class="inventory-chart-total"><span>Positive closing stock</span><strong>${volume(positiveTotalQty)} MT</strong><small>Net stock: ${volume(totalIncludedQty)} MT</small></div></div><div class="inventory-chart-legend"><div class="inventory-chart-legend-heading"><span>LOCATION</span><span>SHARE</span><span>ENDING DETAILS</span></div>${stages.map((stage, index) => `<button type="button" class="inventory-chart-legend-item${Number(stage.endingQtyMT) < 0 ? ' is-negative' : ''}${stage.includedInQuantityTotal === false ? ' is-excluded' : ''}" data-stock-id="${esc(stage.id)}" aria-pressed="false"><i style="--stock-color:${colors[index % colors.length]}" aria-hidden="true"></i><span class="inventory-chart-location">${esc(stage.name)}</span><strong class="inventory-chart-percent">${share(stage)}</strong><span class="inventory-chart-metrics"><span><small>Closing qty</small><b>${volume(stage.endingQtyMT)} MT</b></span><span><small>Average rate</small><b>USD ${number(stage.endingRateUSD)}/MT</b></span><span><small>Inventory value</small><b>USD ${number(stage.endingValueUSD)}</b></span></span></button>`).join('')}</div></div>${negativeNote}`;
    const ledgerRows = stages.map(stage => `<tr><td><strong>${esc(stage.name)}</strong>${stage.includedInTotal === false ? '<br><small>Quantity excluded from total; USD value included</small>' : ''}</td>${['beginningQtyMT', 'beginningValueUSD', 'inflowQtyMT', 'receiptValueUSD', 'addedCostUSD', 'movingAverageRateUSD', 'outflowQtyMT', 'outflowValueUSD', 'adjustmentQtyMT', 'adjustmentValueUSD', 'endingQtyMT', 'endingValueUSD', 'endingRateUSD'].map(key => `<td class="num">${key.endsWith('MT') ? volume(stage[key]) : number(stage[key])}</td>`).join('')}</tr>`).join('');
    const buaRows = (data.buaHistory || []).map(stage => { const mined = (stage.rows || []).find(row => row.source === 'COGP BUA') || {}; return `<tr><td>${esc(stage.period)}</td><td class="num">${volume(stage.beginningQtyMT)}</td><td class="num">${number(stage.beginningValueUSD)}</td><td class="num">${volume(mined.qtyMT)}</td><td class="num">${number(mined.valueUSD)}</td><td class="num">${number(stage.adjustmentValueUSD)}</td><td class="num">${number(stage.movingAverageRateUSD)}</td><td class="num">${volume(stage.endingQtyMT)}</td><td class="num">${number(stage.endingValueUSD)}</td><td class="num">${number(stage.endingRateUSD)}</td><td>${esc(stage.costSource === 'workbook' ? 'Temporary Excel history' : stage.costSource === 'gl' ? 'GL' : 'Awaiting upload')}</td></tr>`; }).join('');
    const flowCards = stages.map(stage => `<details class="stage-card"><summary><strong>${esc(stage.name)}</strong><p>${volume(stage.endingQtyMT)} · USD ${number(stage.endingValueUSD)}</p></summary><div class="table-responsive"><table class="table-accounting"><thead><tr><th>Movement</th><th>Qty MT</th><th>Value USD</th><th>USD/MT</th></tr></thead><tbody>${(stage.rows || []).map(row => `<tr class="${['opening', 'available', 'closing'].includes(row.type) ? 'parent-row' : ''}"><td>${esc(row.label)}</td><td class="num">${volume(row.qtyMT)}</td><td class="num">${number(row.valueUSD)}</td><td class="num">${number(row.rateUSD)}</td></tr>`).join('')}</tbody></table></div></details>`).join('');
    const historyRows = (data.history || []).map(item => `<tr><td>${esc(item.period)}</td><td class="num">${volume(item.totalEndingQtyMT)}</td><td class="num">${number(item.totalEndingValueUSD)}</td><td class="num">${number(item.cogsShippedValUSD)}</td><td>${item.costSource === 'workbook' ? 'Temporary Excel history' : item.costSource === 'gl' || item.hasCostData ? 'GL' : 'Awaiting upload'}</td></tr>`).join('');
    const haulingStatus = hasHaulingRoutes ? `BUA to WP: USD ${number(data.costMapping.haulingBUAToWPUSD)} · BUA to Port: USD ${number(data.costMapping.haulingBUAToPortUSD)} · WP to Port: USD ${number(data.costMapping.haulingWPToPortUSD)}.` : usesWorkbookCosts ? `Excel history: Add Cost Non Wash USD ${number(data.costMapping.haulingToWPUSD)} · Add Cost BP PORT USD ${number(data.costMapping.portAddedCostUSD)} (combined value in Excel).` : 'The three routes are unavailable on the current server.';
    const locationNames = { bua: 'BUA / Mine Site', nonWash: 'Wash Plant — Non Wash', washed: 'Wash Plant — Wash', highAsh: 'Reject (High Ash)', port: 'BP PORT', transitPort: 'In Transit to Port', kunz: 'Kunz', transitKunz: 'In Transit to Kunz', transitBuaWp: 'In Transit BUA to WP', transitBuaPort: 'In Transit BUA to Port', transitWpPort: 'In Transit WP to Port' };
    const manualInputRows = (manualInputs || []).map(input => `<tr><td>${esc(input.period)}</td><td>${esc(input.input_type)}</td><td>${esc(locationNames[input.location] || input.location)}</td><td>${esc(input.reference)}</td><td class="num">${volume(input.qty_mt)}</td><td class="num">${number(input.adjustment_usd)}</td><td>${input.status === 'voided' ? `Voided${input.voided_at ? ` · ${esc(formatSyncTimestamp(input.voided_at))}` : ''}` : 'Active'}</td><td>${input.status === 'active' ? `<button class="btn btn-outline inventory-input-void" data-id="${input.id}" type="button">Void</button>` : '—'}</td></tr>`).join('');
    const inputLocations = Object.entries(locationNames).map(([id, label]) => `<option value="${id}">${esc(label)}</option>`).join('');
    const manualInputsSection = `<section class="table-card inventory-manual-input-card"><div class="card-title">Sales &amp; Inventory Adjustment Input</div><p class="integration-note">Inputs are saved in the database. For October onward they are retained for review but excluded from inventory valuation because the roll-forward uses synced D365 and Google Sheets sources only. Sales reduce stock at the snapshot closing weighted-average rate; sale revenue is tracked separately and is not posted to GL or Financial Statements. Adjustments use signed quantity and USD value. Add a unique reference to prevent duplicate entry.</p><form id="inventoryManualInputForm" class="inventory-manual-input-form"><label>Type<select name="input_type"><option value="sale">Sale</option><option value="adjustment">Adjustment</option></select></label><label>Location<select name="location">${inputLocations}</select></label><label>Reference<input name="reference" required maxlength="100" placeholder="Invoice / adjustment ref"></label><label>Qty MT<input name="qty_mt" type="number" step="0.001" required value="0"></label><label class="inventory-sale-price">Sale price USD/MT<input name="sale_price_usd_per_mt" type="number" min="0" step="0.01"></label><label class="inventory-adjustment-value">Adjustment USD<input name="adjustment_usd" type="number" step="0.01" value="0"></label><label class="inventory-input-reason">Reason<textarea name="reason" rows="2" maxlength="2000"></textarea></label><button class="btn btn-primary" type="submit">Save input</button></form><p id="inventoryInputStatus" class="integration-note" aria-live="polite"></p>${table(['Period', 'Type', 'Location', 'Reference', 'Qty MT', 'Adjustment USD', 'Status', 'Action'], manualInputRows)}</section>`;
    $('#pageContent').innerHTML = `<div class="inventory-page">
        ${sectionHeader('Monthly Inventory Valuation', 'Stock locations and in-transit balances • Qty MT • Value USD • Monthly weighted average per stock.', `<label class="inventory-period-control">Period <input id="inventoryPeriod" type="month" value="${period}" aria-label="Inventory valuation period"></label>${unavailable('First Period Opening Balance')}`)}
        <p class="inventory-notice">${mode === 'ytd' ? `YTD movements: ${data.rangeStart || period} through ${data.periodKey || period}. Closing balances and composition are as of ${data.periodKey || period}; transfer rate is weighted by total outflow.` : `MTD movements and closing balances for ${data.periodKey || period}.`}${data.dynamicSourceStatus === 'active' ? ' October onward uses Google Sheets production movements and D365 GL allocated costs; opening balances use the September baseline. Manual sales and adjustments are retained for review but excluded from this source-based roll-forward.' : ''}</p>
        <p class="inventory-notice">${warnings.join(' ') || 'Cost and tonnage data are available; all values are in USD.'}</p>
        ${manualInputsSection}
        ${data.manualSalesQtyMT ? `<p class="inventory-notice">Manual sales this ${mode.toUpperCase()}: ${volume(data.manualSalesQtyMT)} MT · Revenue ${number(data.manualSalesRevenueUSD)} USD (memo only; excluded from GL).</p>` : ''}
        <p class="source-row">${data.dynamicSourceStatus === 'active' ? `${badge('Google Sheets sync · production movements')} ${badge('D365 GL sync · allocated costs')} ${badge('September baseline opening')}` : `${badge('Historical baseline')}`}</p>
        <div class="inventory-summary"><article class="inventory-total-highlight"><div class="card-title">Total Stock Coal — ${esc(data.periodKey || period)}</div><strong>${volume(totalQty)} MT</strong><p>USD ${number(totalValue)}</p><small>${data.isProvisional ? 'Provisional value — see quantity and cost sources' : 'Includes all stock locations'}</small></article></div>
        ${card(`Coal Stock Composition <span class="subtle">— ${esc(data.periodKey || period)}</span>`, `<p class="inventory-chart-caption">Each slice shows a location’s share of positive closing tonnage. Hover, focus, or select a location to inspect quantity, average rate, and inventory value.</p>${donut}`)}
        <div class="inventory-location-grid">${locationCards}</div>
        <section class="table-card inventory-hauling-card"><div class="card-title">COGP Coal Hauling Routes — from Costing to</div><p>${esc(haulingStatus)}</p><p class="inventory-help">Add Cost Non Wash = Coal Hauling BUA to WP. Add Cost BP PORT = Coal Hauling BUA to Port + Coal Hauling WP to Port + COGP BP PORT. The three routes are read from Costing to in the GL.</p></section>
        ${card('Inventory Balances and Movements', table(['Stockpile', 'Opening MT', 'Opening USD', 'Inflow MT', 'Inflow Value USD', 'Add Cost USD', 'Rate Transfer USD/MT', 'Outflow MT', 'Outflow USD', 'Adj MT', 'Adj USD', 'Closing MT', 'Closing USD', 'Rate Closing USD/MT'], ledgerRows))}
        <details class="stage-card inventory-bua-details"><summary><strong>Mining BUA Monthly History</strong><p>Opening, coal mined, adjustments, transfer rate, and closing.</p></summary>${table(['Month', 'Opening MT', 'Opening USD', 'Coal mined MT', 'Coal mined USD', 'Adj USD', 'Rate Transfer USD/MT', 'Closing MT', 'Closing USD', 'Rate Closing USD/MT', 'Cost Source'], buaRows)}</details>
        <div class="stockpile-flow">${flowCards}</div>
        ${card('Closing Balance History — rolled forward from the first period', table(['Month', 'Total Stock MT', 'Stock Value USD', 'COGS USD', 'Cost Data'], historyRows))}</div>`;
    const form = $('#inventoryManualInputForm');
    const typeSelect = form.elements.input_type;
    const updateInputFields = () => {
        const sale = typeSelect.value === 'sale';
        form.querySelector('.inventory-sale-price').hidden = !sale;
        form.querySelector('.inventory-adjustment-value').hidden = sale;
        form.elements.sale_price_usd_per_mt.required = sale;
        if (sale && !['port', 'transitPort', 'kunz', 'transitKunz'].includes(form.elements.location.value)) form.elements.location.value = 'port';
    };
    typeSelect.addEventListener('change', updateInputFields);
    form.elements.location.addEventListener('change', () => { if (typeSelect.value === 'sale' && !['port', 'transitPort', 'kunz', 'transitKunz'].includes(form.elements.location.value)) form.elements.location.value = 'port'; });
    updateInputFields();
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const status = $('#inventoryInputStatus');
        const payload = Object.fromEntries(new FormData(form));
        payload.period = period;
        status.textContent = 'Saving and refreshing valuation…';
        try {
            await postJson('/api/inventory-inputs', payload);
            await inventory(period, mode);
        } catch (error) { status.textContent = error.message; }
    });
    document.querySelectorAll('.inventory-input-void').forEach(button => button.addEventListener('click', async () => {
        const reason = window.prompt('Reason for voiding this inventory input:');
        if (!reason?.trim()) return;
        try { await postJson(`/api/inventory-inputs/${button.dataset.id}/void`, { reason }); await inventory(period, mode); }
        catch (error) { window.alert(error.message); }
    }));
    $('#inventoryPeriod').addEventListener('change', event => { $('#period').value = event.target.value; render(); });
    const donutLayout = document.querySelector('.inventory-donut-layout');
    if (donutLayout) {
        let hoveredId = null;
        let selectedId = null;
        const updateDonutDetail = () => {
            const id = selectedId || hoveredId;
            const stage = id ? stages.find(item => item.id === id) : null;
            const label = $('#inventoryDonutCenterLabel');
            const value = $('#inventoryDonutCenterValue');
            const volumeText = $('#inventoryDonutCenterVolume');
            const hint = $('#inventoryDonutCenterShare');
            if (stage) {
                label.textContent = stage.name;
                value.textContent = `${volume(stage.endingQtyMT)} MT`;
                volumeText.textContent = `USD ${number(stage.endingValueUSD)}`;
                hint.textContent = `${share(stage)} of positive stock · USD ${number(stage.endingRateUSD)}/MT`;
            } else {
                label.textContent = 'CLOSING STOCK VALUE';
                value.textContent = `USD ${number(totalValue)}`;
                volumeText.textContent = `Net ${volume(totalIncludedQty)} MT`;
                hint.textContent = 'Hover or select a location';
            }
            donutLayout.querySelectorAll('[data-stock-id]').forEach(item => {
                const active = id === item.dataset.stockId;
                item.classList.toggle('is-active', active);
                if (item.matches('.inventory-chart-legend-item')) item.setAttribute('aria-pressed', selectedId === item.dataset.stockId ? 'true' : 'false');
            });
        };
        donutLayout.querySelectorAll('[data-stock-id]').forEach(item => {
            item.addEventListener('pointerenter', () => { hoveredId = item.dataset.stockId; updateDonutDetail(); });
            item.addEventListener('pointerleave', () => { hoveredId = null; updateDonutDetail(); });
            item.addEventListener('focus', () => { hoveredId = item.dataset.stockId; updateDonutDetail(); });
            item.addEventListener('blur', () => { hoveredId = null; updateDonutDetail(); });
            if (item.matches('.inventory-chart-legend-item')) item.addEventListener('click', () => { selectedId = selectedId === item.dataset.stockId ? null : item.dataset.stockId; updateDonutDetail(); });
        });
    }
}

async function equipmentCost(period, mode) {
    const data = await api(`/api/equipment-cost?period=${encodeURIComponent(period)}&mode=${encodeURIComponent(mode)}`);
    const activityOptions = (data.activities || []).map(item => `<option value="${esc(item.activity)}">${esc(item.activity)}</option>`).join('');
    const d365Amount = data.currency !== 'USD';
    const amountLabel = data.currencyLabel || 'USD';
    const formatAmount = value => d365Amount ? `${esc(amountLabel)} ${signedNumber(Number(value || 0), 2)}` : money(value);
    const moneyOrMissing = value => value === null || value === undefined ? `${esc(amountLabel)} -` : formatAmount(value);
    const mapTargets = ['Coal Hauling BUA to Port','Coal Hauling BUA to WP','Coal Hauling WP to Port','Coal Loading BUA to Port','Coal Loading BUA to WP','Coal Loading WP to Port'];
    const mappingRows = (data.activityMappings || []).map(item => `<tr><td>${esc(item.activityCode || '—')}</td><td>${esc(item.description)}</td><td class="num">${qty(item.transactionCount)}</td><td class="num">${formatAmount(item.costAmount)}</td><td class="num">${qty(item.mappedByProduction)}</td><td class="num">${qty(item.mappedByCrosswalk)}</td><td><select data-equipment-map-select="${esc(item.activityCode || '')}" ${item.activityCode ? '' : 'disabled'}><option value="">Unmapped / no approved route</option>${mapTargets.map(target => `<option value="${esc(target)}" ${item.targetActivity === target ? 'selected' : ''}>${esc(target)}</option>`).join('')}</select></td><td><button class="btn btn-outline btn-small" type="button" data-equipment-map-save="${esc(item.activityCode || '')}" ${item.activityCode ? '' : 'disabled'}>Save</button></td></tr>`).join('') || '<tr><td colspan="8" class="table-empty">No D365 Activity rows for this period.</td></tr>';
    const mappingPanel = d365Amount ? card('D365 Activity Crosswalk', `<p class="integration-note">Production unit + month is the first mapping rule. This crosswalk is used only when production does not identify one activity. Rows without a valid match remain Unmapped.</p><div class="equipment-table-tools"><span>${qty(data.sources?.inventory?.mappedByProduction || 0)} mapped from production · ${qty(data.sources?.inventory?.mappedByCrosswalk || 0)} from crosswalk · ${qty(data.unmapped?.transactionCount || 0)} Unmapped</span><span id="equipmentMappingStatus" role="status"></span></div><div class="table-responsive equipment-cost-table"><table class="table-accounting"><thead><tr><th>Activity code</th><th>D365 Activity</th><th>Transactions</th><th>CostAmount</th><th>Production match</th><th>Crosswalk match</th><th>Equipment activity</th><th></th></tr></thead><tbody>${mappingRows}</tbody></table></div>`) : '';
    const inventorySource = data.sources?.inventory;
    $('#pageContent').innerHTML = `<div class="equipment-page-header"><div><span>ACTIVITY OVERVIEW</span><h2>Production &amp; cost</h2><p>Compare production and inventory costs by activity.</p></div><b>INHOUSE MINING</b></div><div class="equipment-filters"><label>Activity<select id="equipmentActivityFilter"><option value="all">All activities</option>${activityOptions}</select></label><label>Search Unit<input id="equipmentUnitSearch" type="search" placeholder="Type a unit code"></label></div>${(data.notes || []).map(note => `<div class="financial-notice equipment-note">${esc(note)}</div>`).join('')}<div class="equipment-source-line"><span>Inventory: ${inventorySource?.available ? (d365Amount ? `${esc(inventorySource.source)} · ${qty(inventorySource.transactionCount || 0)} posted transactions` : 'historical baseline loaded') : inventorySource?.source ? `${esc(inventorySource.source)} · no posted transactions for this period` : 'not available'}</span><span>Production: ${esc(data.sources?.production || 'Google Sheets')}</span><span>${d365Amount ? 'D365 currency field: not provided' : `Employee Cost / Fuel: ${data.sources?.employeeAndFuel?.available ? `DB sheet · ${esc(data.sources.employeeAndFuel.year)}` : 'DB reference unavailable for this period'}`}</span></div>${mappingPanel}<div id="equipmentActivityCards"></div>${card('Equipment by Activity', '<div class="equipment-table-tools"><span id="equipmentUnitCount" class="integration-note"></span><span class="integration-note">Search applies to this table only.</span></div><div id="equipmentUnitTable"></div>')}`;

    const renderActivities = () => {
        const selected = $('#equipmentActivityFilter').value;
        const activities = (data.activities || []).filter(item => selected === 'all' || item.activity === selected);
        const cards = activities.map(item => {
            const groups = item.itemGroups || [];
            const totalAbsolute = groups.reduce((sum, group) => sum + Math.abs(Number(group.value || 0)), 0);
            let angle = 0;
            const colors = ['#1769e0', '#0e9f9a', '#f59e0b', '#8b5cf6', '#ef4444', '#64748b', '#14b8a6'];
            const stops = groups.filter(group => Number(group.value) !== 0).map((group, index) => {
                const start = angle; angle += totalAbsolute ? Math.abs(Number(group.value)) / totalAbsolute * 360 : 0;
                return `${colors[index % colors.length]} ${start}deg ${angle}deg`;
            });
            const legend = groups.map((group, index) => `<div><i style="--equipment-color:${group.value < 0 ? '#b42318' : colors[index % colors.length]}"></i><span>${esc(group.label)}${group.credit ? ' · Credit' : ''}</span><b>${formatAmount(group.value)}</b><em>${qty(group.percentage)}%</em></div>`).join('') || `<div><i style="--equipment-color:#dce4ee"></i><span>EMPLOYEE COST (DB)</span><b>—</b><em>—</em></div>`;
            const chart = `<div class="equipment-breakdown"><div class="equipment-chart-heading"><b>COST BREAKDOWN</b><b>${esc(amountLabel)}</b></div><div class="equipment-chart-row"><div class="equipment-donut" style="--equipment-chart:${stops.length ? stops.join(',') : '#e2e8f0 0deg 360deg'}"></div><div class="equipment-legend">${legend}</div></div></div>`;
            const subtitle = item.hasProduction ? 'Production available' : 'Production unavailable';
            const costHeading = d365Amount ? 'D365 INVENTORY COST · RAW SIGN' : 'INVENTORY + EMPLOYEE COST';
            const footnote = d365Amount ? `${subtitle} · D365 CostAmount is shown with its source sign; currency is not exposed by this entity.` : `Includes Employee Cost from DB · ${subtitle} · Employee Cost is not allocated to units${item.employeeCostUSD !== null && !item.employeeCostComplete ? ' · Employee Cost incomplete' : ''}`;
            return `<article class="equipment-activity-card"><div class="equipment-activity-title"><div><span>ACTIVITY</span><h3>${esc(item.activity)}</h3></div><div class="equipment-total-cost"><span>${costHeading}</span><b>${moneyOrMissing(item.totalCostAmount)}</b></div></div><div class="equipment-kpis"><div><span>Production</span><b>${item.productionMT === null ? '—' : `${qty(item.productionMT)} MT`}</b></div><div><span>Units</span><b>${item.unitCount ? qty(item.unitCount) : '—'}</b></div><div><span>${d365Amount ? 'CostAmount / MT' : 'Cost / MT'}</span><b>${moneyOrMissing(item.inventoryCostPerMT)}</b></div></div>${chart}<p class="equipment-activity-footnote">${footnote}</p></article>`;
        }).join('');
        $('#equipmentActivityCards').innerHTML = `<div class="equipment-activity-grid">${cards || '<div class="integration-note">No activity matches the selected period.</div>'}</div>`;
    };
    const renderUnits = () => {
        const query = $('#equipmentUnitSearch').value.trim().toUpperCase();
        const rows = (data.units || []).filter(row => !query || row.unit.toUpperCase().includes(query));
        $('#equipmentUnitCount').textContent = `${qty(rows.length)} of ${qty((data.units || []).length)} unit rows`;
        const html = rows.map(row => {
            const groupDetails = (row.itemGroups || []).map(group => `${esc(group.label)}: ${formatAmount(group.value)}`).join('<br>') || '—';
            const fuelEstimate = row.fuelCostEstimateUSD === null || row.fuelCostEstimateUSD === undefined ? 'USD -' : money(row.fuelCostEstimateUSD);
            return `<tr><td>${esc(row.activity)}</td><td><b>${esc(row.unit)}</b></td><td class="num">${row.haulingMT === null ? '—' : qty(row.haulingMT)}</td><td class="num">${row.loaderMT === null ? '—' : qty(row.loaderMT)}</td><td class="num">${row.fuelLitres === null ? '—' : qty(row.fuelLitres)}</td><td class="num">${row.fuelPerHaulingMT === null ? '—' : qty(row.fuelPerHaulingMT)}</td><td class="num">${row.fuelPerLoaderMT === null ? '—' : qty(row.fuelPerLoaderMT)}</td><td class="num">${fuelEstimate}</td><td class="num">${moneyOrMissing(row.otherInventoryCostAmount)}</td><td class="num"><b>${moneyOrMissing(row.totalInventoryCostAmount)}</b></td><td>${groupDetails}</td></tr>`;
        }).join('');
        $('#equipmentUnitTable').innerHTML = `<div class="table-responsive equipment-cost-table"><table class="table-accounting"><thead><tr><th>Activity</th><th>Unit</th><th>Hauling MT</th><th>Loader MT</th><th>Fuel litres</th><th>Fuel / Hauling MT</th><th>Fuel / Loader MT</th><th>Est. fuel USD</th><th>Other inventory ${esc(amountLabel)}</th><th>Total inventory ${esc(amountLabel)}</th><th>${d365Amount ? 'Item number detail' : 'Item group detail'}</th></tr></thead><tbody>${html || '<tr><td colspan="11">No units found for this period or search.</td></tr>'}</tbody></table></div>`;
    };
        $('#equipmentActivityFilter').addEventListener('change', renderActivities);
    $('#equipmentUnitSearch').addEventListener('input', renderUnits);
        $('#pageContent').querySelectorAll('[data-equipment-map-save]').forEach(button => button.addEventListener('click', async () => {
            const code = button.dataset.equipmentMapSave;
            const select = $('#pageContent').querySelector(`[data-equipment-map-select="${CSS.escape(code)}"]`);
            const status = $('#equipmentMappingStatus');
            button.disabled = true;
            try {
                const response = await fetch(`/api/equipment-cost/mappings/${encodeURIComponent(code)}`, { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: JSON.stringify({ target_activity: select.value || null }) });
                const result = await response.json().catch(() => null);
                if (!response.ok) throw new Error(result?.message || `Request failed: ${response.status}`);
                if (status) status.textContent = `Saved mapping for ${code}.`;
                await equipmentCost(period, mode);
                const refreshedStatus = $('#equipmentMappingStatus');
                if (refreshedStatus) refreshedStatus.textContent = `Saved mapping for ${code}.`;
                return;
            } catch (error) {
                if (status) status.textContent = error.message || 'Could not save crosswalk.';
                button.disabled = false;
            }
        }));
    renderActivities(); renderUnits();
}

async function financial(period, mode) {
    const data = await api(`/api/financial-statements?period=${period}&mode=${mode}`);
    const report = data.report || {};
    const periods = report.periods || Object.keys(report.profitLoss?.[0]?.months || {});
    const formatStatementAmount = value => value == null ? '—' : signedNumber(value, 0);
    const reportTable = lines => {
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const headers = ['Description', `YTD ${String(report.comparativeYear).slice(-2)}`, `YTD ${String(report.year).slice(-2)}`, ...periods.map(month => `${monthNames[Number(month.slice(5)) - 1]}-${month.slice(2, 4)}`)];
        const rows = (lines || []).map(line => {
            if (line.kind === 'heading') return `<tr class="parent-row"><td colspan="${headers.length}">${esc(line.label || line.id)}</td></tr>`;
            const classes = [line.kind === 'total' ? 'financial-final' : ''].filter(Boolean).join(' ');
            const cells = [
                `<td class="financial-description">${esc(line.label || line.id)}</td>`,
                `<td class="num financial-ytd financial-ytd-first">${formatStatementAmount(line.comparative)}</td>`,
                `<td class="num financial-ytd financial-ytd-second">${formatStatementAmount(line.ytd)}</td>`,
                ...periods.map(month => `<td class="num ${month === period ? 'selected-period' : ''}">${formatStatementAmount(line.months?.[month])}</td>`),
            ];
            return `<tr class="${classes}">${cells.join('')}</tr>`;
        });
        const header = `<tr><th class="financial-description">Description</th><th class="financial-ytd financial-ytd-first">YTD ${String(report.comparativeYear).slice(-2)}</th><th class="financial-ytd financial-ytd-second ${mode === 'ytd' ? 'selected-period' : ''}">YTD ${String(report.year).slice(-2)}</th>${periods.map(month => `<th class="${mode === 'mtd' && month === period ? 'selected-period' : ''}">${monthNames[Number(month.slice(5)) - 1]}-${month.slice(2, 4)}${month >= report.glStartPeriod ? `<br><small>${report.glMonths?.[month]?.rows ? 'GL' : 'No GL'}</small>` : ''}</th>`).join('')}</tr>`;
        return `<div class="table-responsive"><table class="table-accounting financial-report-table"><thead>${header}</thead><tbody>${rows.length ? rows.join('') : `<tr><td colspan="${headers.length}">No data available.</td></tr>`}</tbody></table></div>`;
    };
    const diagnostics = data.diagnostics || {};
    const accountRows = (data.accountBalances || []).map(row => `<tr><td>${esc(row.code)}</td><td>${esc(row.name)}</td><td>${esc(row.mapping)}</td><td class="num">${formatStatementAmount(row.baseUSD)}</td><td class="num">${formatStatementAmount(row.glMovementUSD)}</td><td class="num">${formatStatementAmount(row.closingUSD)}</td></tr>`);
    $('#pageContent').innerHTML = `
        ${sectionHeader('Financial Statements', 'Synced D365 GL movements are used when available. Historical opening balances remain based on the approved financial baseline. Amounts in USD.', `<button id="financialMappingToggle" class="btn btn-outline" type="button">Account Mapping</button><label>Month <input type="month" value="${period}" disabled></label>`)}
        <p class="financial-notice">${mode === 'ytd' ? `YTD P&amp;L through ${esc(period)}; balance sheet is the closing position as of that month.` : `MTD P&amp;L for ${esc(period)}; balance sheet is the closing position as of that month.`}</p>
        <p class="financial-notice">${esc(period)} GL rows. Accounts requiring mapping: ${(diagnostics.unmappedAccounts || []).length}. Baseline difference: ${money(diagnostics.baselineDifferenceUSD ?? diagnostics.balanceDifferenceUSD)}.</p>
        <p class="source-row">${data.dataSource?.startsWith('d365-gl') ? `${badge('D365 synced GL')} ${badge('Financial baseline opening balances')}` : badge('financial_baseline.json')}</p>
        <section id="financialMappingPanel" class="financial-mapping-panel" hidden></section>
        ${card(`${esc(companyName)} — Profit & Loss Statement (USD)`, reportTable(report.profitLoss || data.sections?.profitLoss), 'financial-reference-card')}
        ${card(`${esc(companyName)} — Balance Sheet (USD)`, reportTable(report.balanceSheet || data.sections?.balanceSheet), 'financial-reference-card')}
        <details class="stage-card financial-accounts-card"><summary><strong>Balances by Account</strong><p>September balance, subsequent GL movements, and closing balance. Debit positive, credit negative.</p></summary>${table(['COA', 'Account Name', 'Balance Sheet Mapping', 'September Base Balance', 'GL Movement', 'Closing Balance'], accountRows)}</details>`;

    const toggle = $('#financialMappingToggle');
    const panel = $('#financialMappingPanel');
    toggle.addEventListener('click', async () => {
        toggle.disabled = true;
        try {
            const payload = await api('/api/financial-mappings');
            const accounts = payload.accounts || {};
            const choices = payload.choices || [];
            const renderMappings = (filter = '') => {
                const query = filter.trim().toLowerCase();
                const matches = Object.entries(accounts).filter(([code, item]) => !query || `${code} ${item.name || ''} ${item.rowId || ''}`.toLowerCase().includes(query));
                panel.querySelector('#financialMappingRows').innerHTML = matches.map(([code, item]) => {
                    const section = /^[3-9]/.test(code) ? 'Profit &amp; Loss' : 'Balance Sheet';
                    const validChoices = choices.filter(choice => choice.section === (section.includes('Profit') ? 'Profit & Loss' : 'Balance Sheet'));
                    const options = validChoices.map(choice => `<option value="${esc(choice.id)}" ${choice.id === item.rowId ? 'selected' : ''}>${esc(choice.label)} (${esc(choice.id)})</option>`).join('');
                    return `<tr data-account-code="${esc(code)}"><td>${esc(code)}</td><td>${esc(item.name)}</td><td>${section}</td><td class="num">${item.referenceSep == null ? '—' : formatStatementAmount(item.referenceSep)}</td><td class="num">${item.referenceOct == null ? '—' : formatStatementAmount(item.referenceOct)}</td><td><select aria-label="Financial statement row for ${esc(code)}">${options}</select></td><td><button class="btn btn-primary financial-mapping-save" type="button">Save</button></td></tr>`;
                }).join('') || `<tr><td colspan="7">No account mapping found.</td></tr>`;
                panel.querySelector('#financialMappingCount').textContent = `${matches.length.toLocaleString('en-US')} of ${Object.keys(accounts).length.toLocaleString('en-US')} accounts`;
            };
            panel.innerHTML = `<div class="financial-mapping-heading"><div><h3>Financial Account Mapping</h3><p>Assign each account to its statement row. September and October reference amounts are retained for comparison.</p></div><button id="financialMappingClose" class="btn btn-outline" type="button">Close</button></div><div class="financial-mapping-tools"><input id="financialMappingSearch" type="search" placeholder="Search account code, name, or mapped row"><span id="financialMappingCount"></span><span id="financialMappingStatus" role="status"></span></div><div class="table-responsive financial-mapping-table"><table class="table-accounting"><thead><tr><th>Account</th><th>Account Name</th><th>Statement</th><th>Sep Reference</th><th>Oct Reference</th><th>Mapped Row</th><th></th></tr></thead><tbody id="financialMappingRows"></tbody></table></div>`;
            panel.hidden = false;
            renderMappings();
            panel.querySelector('#financialMappingClose').addEventListener('click', () => { panel.hidden = true; });
            panel.querySelector('#financialMappingSearch').addEventListener('input', event => renderMappings(event.currentTarget.value));
            panel.querySelector('#financialMappingRows').addEventListener('click', async event => {
                const button = event.target.closest('.financial-mapping-save');
                if (!button) return;
                const row = button.closest('tr');
                button.disabled = true;
                const status = panel.querySelector('#financialMappingStatus');
                status.textContent = `Saving ${row.dataset.accountCode}…`;
                try {
                    const result = await fetch(`/api/financial-mappings/${encodeURIComponent(row.dataset.accountCode)}`, { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: JSON.stringify({ rowId: row.querySelector('select').value }) });
                    const response = await result.json();
                    if (!result.ok) throw new Error(response.error || response.message || `Request failed: ${result.status}`);
                    status.textContent = `Saved ${row.dataset.accountCode}. Refreshing statements…`;
                    await financial(period, mode);
                } catch (error) {
                    status.textContent = `Save failed: ${error.message}`;
                    button.disabled = false;
                }
            });
        } catch (error) {
            panel.hidden = false;
            panel.innerHTML = `<p class="financial-mapping-error">Could not load account mappings: ${esc(error.message)}</p>`;
        } finally {
            toggle.disabled = false;
        }
    });
}

async function integration() {
    const [data, production, d365] = await Promise.all([api('/api/integration-status'), api(`/api/production-summary?period=${$('#period').value}&mode=mtd`), api('/api/d365/entities')]);
    const d365LastSyncedAt = (d365.entities || []).filter(item => item.last?.status === 'success' && item.last?.updated_at).map(item => item.last.updated_at).sort().at(-1) || null;
    const connector = (name, detail, status, source, lines, button, statusClass) => `<article class="connector-card"><div><div class="connector-heading"><h3>${name}</h3>${badge(source)}</div><p>${detail}</p><div class="connector-details">${lines.map(line => `<div>${line}</div>`).join('')}</div><div class="connector-actions">${button}</div></div><div><span class="status ${statusClass}">${esc(status)}</span></div></article>`;
    const display = value => value === undefined || value === null || value === '' ? '—' : esc(value);
    const entityRows = (d365.entities || []).map(item => { const last = item.last; return `<tr><td><strong>${esc(item.label)}</strong><br><small>${esc(item.key)}</small></td><td>${esc(last?.status || 'pending')}</td><td class="num">${qty(item.staging_count)}</td><td class="num">${qty(last?.fetched || 0)}</td><td>${display(last?.message)}</td><td><button class="btn btn-outline d365-staging-view" data-entity="${esc(item.key)}" data-label="${esc(item.label)}" type="button">View Staging</button></td></tr>`; }).join('');
    const recentRows = (production.recentTickets || []).map(ticket => `<tr><td>${display(ticket['Tgl Keluar'])} ${display(ticket['Jam Keluar'])}</td><td><strong>${display(ticket.Product)}</strong></td><td>${display(ticket['No Pol'])}</td><td>${display(ticket.Sopir)}</td><td>${display(ticket.WEIGHBRIDGE || ticket.source)}</td><td class="num">${ticket.BRUTO == null ? '—' : signedNumber(ticket.BRUTO, 0)}</td><td class="num">${ticket.TARA == null ? '—' : signedNumber(ticket.TARA, 0)}</td><td class="num">${ticket.source === 'Google Sheets' ? '—' : signedNumber(ticket.NETTO || 0, 0)}</td><td class="num val-text">${qty((Number(ticket.NETTO) || 0) / 1000)} MT</td><td>${display(ticket.Operator)}</td></tr>`).join('');
    $('#pageContent').innerHTML = `
        ${sectionHeader('Integration & Real-Time Data Sources', 'Microsoft Dynamics 365 and Google Sheets production sources.')}
        <div class="connector-grid">${connector('Microsoft Dynamics 365 OData Connector', 'Reads D365 connection settings from the Laravel environment configuration.', data.d365.status === 'configured' ? 'Configured' : 'Not configured', 'environment', [`<b>Environment URL:</b> <code>${esc(data.d365.environmentUrl || 'Not configured')}</code>`, `<b>Sync Period:</b> <code>${esc($('#period').value)}</code>`, `<b>Auto Sync:</b> <code>${data.d365.autoSync ? 'Enabled' : 'Disabled'}</code>`, `<b>Last Sync:</b> ${esc(formatSyncTimestamp(d365LastSyncedAt))}`, `<b>Local Records:</b> ${qty(data.database.glEntries)} GL records`], `<button id="d365SyncAll" class="btn btn-primary" type="button">${Number(data.database.glEntries || 0) === 0 ? 'Initial D365 Sync' : 'Sync Selected Month'}</button><button id="viewGlData" class="btn btn-outline" type="button">View GL Data</button>`, data.d365.status === 'configured' ? 'online' : 'manual')}${connector('Google Sheets Production Connector', 'Preview and sync activity data from the Produksi tab.', data.googleSheets.status === 'configured' ? 'Configured' : 'Not configured', 'Google Sheets API', [`<b>Spreadsheet ID:</b> <code>${esc(data.googleSheets.spreadsheetId || '—')}</code>`, `<b>Sheet:</b> <code>${esc(data.googleSheets.sheetName || 'Produksi')}</code>`, `<b>Service account:</b> <code>${esc(data.googleSheets.serviceAccountEmail || '—')}</code>`, `<b>Credential:</b> ${esc(data.googleSheets.credentialStatus || 'Unavailable')}`, `<b>Last Sync:</b> ${esc(formatSyncTimestamp(data.googleSheets.lastSyncedAt))}`], `<button id="googleSheetsSync" class="btn btn-primary" type="button">Sync Production Now</button><button id="googleSheetsPreview" class="btn btn-outline" type="button">Preview Sheet</button><div id="googleSheetsSyncStatus" class="integration-note" role="status"></div>`, data.googleSheets.status === 'configured' ? 'online' : 'manual')}</div>
        ${card('D365 Staging Status', `<p class="integration-note d365-flow-note"><strong>Alur sync:</strong> Semua entity OData ditarik ke staging. Setelah <em>GL Account Entries</em> berhasil, sistem otomatis menerapkan allocation mapping dan melakukan upsert ke <code>gl_entries</code>; GL tidak berhenti di staging dan tidak perlu klik Import to GL lagi. Main Account dan Findim mapping juga memperbarui hierarchy/rule terkait. Entity lain tetap tersedia di staging. <strong>View Staging</strong> untuk payload OData; <strong>View GL Data</strong> untuk transaksi GL yang dipakai dashboard.</p>${table(['Entity', 'Status', 'Staging Rows', 'Last Fetched', 'Message', ''], entityRows)}`)}
        ${card('Accrual Input — Add to Report Cost <span class="status manual">Manual</span>', `<p class="integration-note">Accrual menambah amount ke <strong>Report Cost</strong> dan <strong>Cost/MT</strong> pada periode terpilih. Amount = Qty × Rate dalam USD penuh.</p><div class="accrual-form-grid"><label>Periode<input type="month" value="${esc($('#period').value)}" disabled></label><label>Akun COA (5–9)<select disabled><option>Select account</option></select></label><label>Qty (MT)<input type="number" value="1" disabled></label><label>Rate (USD/MT)<input type="number" disabled></label><label>Amount (USD)<input type="text" value="0" disabled></label><label>Kategori<select disabled><option>Select category</option></select></label><button class="btn btn-primary" type="button" disabled>Add Accrual</button></div><p class="integration-note">Entry form remains available only after authentication is enabled.</p><div class="table-responsive"><table class="table-accounting"><thead><tr><th>Periode</th><th>Akun / Item Description</th><th>Kategori</th><th>Qty (MT)</th><th>Rate (USD/MT)</th><th>Amount (USD)</th><th></th></tr></thead><tbody><tr><td colspan="7" class="center">Belum ada accrual.</td></tr></tbody></table></div>`)}
        ${card('Latest 15 Production Records', table(['Date & Time', 'Product', 'Vehicle Plate', 'Driver', 'Weighbridge', 'BRUTO (Kg)', 'TARA (Kg)', 'NETTO (Kg)', 'NETTO (MT)', 'Operator'], recentRows))}`;
    $('#viewGlData').addEventListener('click', () => viewGlData($('#period').value));
    $('#googleSheetsPreview').addEventListener('click', async event => { const button = event.currentTarget; const status = $('#googleSheetsSyncStatus'); button.disabled = true; status.textContent = 'Reading preview…'; try { const result = await postJson('/api/google-sheets/preview', {}); status.textContent = `Preview: ${qty(result.rows)} dated rows, ${qty(result.details)} activity details, ${qty(result.db_accrual_rows)} DB accrual rows; periods ${result.periods.join(', ')}. First date: ${result.samples?.[0]?.production_date || '—'}.`; } catch (error) { status.textContent = error.message; } finally { button.disabled = false; } });
    $('#googleSheetsSync').addEventListener('click', async event => { const button = event.currentTarget; const status = $('#googleSheetsSyncStatus'); button.disabled = true; status.className = 'integration-note sheets-sync-result'; status.textContent = 'Sync in progress…'; const overlay = document.createElement('div'); overlay.className = 'sheets-sync-overlay'; overlay.setAttribute('role','status'); overlay.setAttribute('aria-live','polite'); overlay.innerHTML = '<div class="sheets-sync-dialog"><i></i><strong>Syncing production data</strong><span>Reading rows and updating the dashboard database…</span></div>'; document.body.appendChild(overlay); try { const result = await postJson('/api/google-sheets/sync', {}); const timestamp = formatSyncTimestamp(result.synced_at); status.className = 'integration-note sheets-sync-result is-success'; status.innerHTML = `<strong>✓ Sync successful</strong><span>${qty(result.rows)} production dates · ${qty(result.details)} activity details · ${qty(result.metrics)} monthly metrics · ${qty(result.db_cost_rows)} monthly cost rows · ${qty(result.db_accrual_rows)} DB accrual rows</span><span>Periods: ${esc(result.periods.join(', '))} · Completed: ${esc(timestamp)}</span>`; } catch (error) { status.className = 'integration-note sheets-sync-result is-error'; status.textContent = `Sync failed: ${error.message}`; } finally { overlay.remove(); button.disabled = false; } });
    document.querySelectorAll('.d365-staging-view').forEach(button => button.addEventListener('click', () => viewD365Staging(button.dataset.entity, button.dataset.label)));
    $('#d365SyncAll').addEventListener('click', async event => {
        const button = event.currentTarget;
        button.disabled = true;
        const period = $('#period').value;
        const [year, month] = period.split('-').map(Number);
        const dateFrom = `${period}-01`;
        const dateTo = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);
        try {
            const forceFull = Number(data.database.glEntries || 0) === 0;
            const response = await fetch('/api/d365/sync', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: JSON.stringify({ entity: 'all', date_from: dateFrom, date_to: dateTo, force_full: forceFull }) });
            if (!response.ok) throw new Error(`Sync request failed: ${response.status}`);
            const payload = await response.json();
            const result = payload.data || payload;
            if (result.already_running) {
                button.textContent = 'Sync Already Running';
                await refreshD365Progress();
                return;
            }
            writeD365SyncState({ period, dateFrom, dateTo, baseline: Object.fromEntries((d365.entities || []).map(item => [item.key, item.last?.updated_at || null])), finished: false });
            mountD365ProgressMonitor();
            button.textContent = 'Queued';
        } catch (error) {
            button.textContent = 'Sync Failed';
            console.error(error);
        } finally { button.disabled = false; }
    });
    mountD365ProgressMonitor();
}

async function configuration() {
    const data = await api('/api/config');
    const label = key => key.replace(/([A-Z])/g, ' $1').replace(/^./, value => value.toUpperCase());
    const editable = {
        system: ['companyName', 'currency', 'currencyUnit', 'periodYear', 'periodMonth', 'amountScale'],
        d365Config: ['enabled', 'entityName', 'syncIntervalMinutes', 'autoSync'],
        sheetsConfig: ['enabled', 'spreadsheetId', 'sheetName', 'range', 'syncIntervalMinutes', 'autoSync', 'gid'],
    };
    const formatConfigDate = value => {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? esc(value) : `${new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'Asia/Bangkok' }).format(date)} WIB`;
    };
    const sanitize = (value, key = '') => {
        if (/secret|password|token/i.test(key)) return 'Configured securely';
        if (Array.isArray(value)) return value.map(item => sanitize(item));
        if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([childKey, childValue]) => [childKey, sanitize(childValue, childKey)]));
        return value;
    };
    const display = (key, value) => {
        if (value === null || value === undefined || value === '') return '—';
        if (typeof value === 'string' && /^last.*(?:sync|delete)/i.test(key)) return formatConfigDate(value);
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
    const sections = Object.entries(data || {}).filter(([section]) => !['d365Config', 'sheetsConfig', 'sheetsSync', 'inventorySetup'].includes(section)).map(([section, values]) => {
        const rows = Object.entries(values && typeof values === 'object' && !Array.isArray(values) ? values : { value: values }).map(([key, value]) => {
            const canEdit = editable[section]?.includes(key) && (typeof value !== 'object' || value === null);
            return `<tr><th>${esc(label(key))}</th><td>${canEdit ? editor(section, key, value) : display(key, value)}</td></tr>`;
        }).join('');
        return `<section class="table-card config-card"><div class="card-title">${esc(label(section))}</div><div class="table-responsive"><table class="table-accounting config-table"><tbody>${rows || '<tr><td colspan="2">No configuration available.</td></tr>'}</tbody></table></div></section>`;
    }).join('');
    $('#pageContent').innerHTML = `${sectionHeader('Configuration', 'System, connector, and period settings used by the dashboard.', `<span class="config-source">Source: /api/config</span>`)}<p class="financial-notice">Editable fields are marked with an input. Sensitive credentials and nested setup details remain read-only.</p><div class="config-actions"><button id="configSaveAll" class="btn btn-primary" type="button">Save All Changes</button><p id="config-status" class="config-status" aria-live="polite"></p></div><div class="config-grid">${sections || '<div class="table-card error-card">No configuration available.</div>'}</div>`;
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

async function allocationMapping() {
    const [data, hierarchy] = await Promise.all([api('/api/d365/mappings'), api('/api/d365/account-hierarchy')]);
    const allocationRules = data.rows || [];
    const ruleFilters = { group: '', activity: '', location: '', valuation: '', costing: '', priority: '', status: 'all' };
    const ruleMatches = row => `${row.group_activity_code || row.group_activity || ''} ${row.group_activity_name || ''}`.toLowerCase().includes(ruleFilters.group) && `${row.activity_code || row.activity || ''} ${row.activity_name || ''}`.toLowerCase().includes(ruleFilters.activity) && `${row.location_code || row.location || ''} ${row.location_name || ''}`.toLowerCase().includes(ruleFilters.location) && String(row.valuation_to || '').toLowerCase().includes(ruleFilters.valuation) && String(row.costing_to || '').toLowerCase().includes(ruleFilters.costing) && String(row.priority ?? '').includes(ruleFilters.priority) && (ruleFilters.status === 'all' || (row.is_active ? 'active' : 'disabled') === ruleFilters.status);
    const renderRuleRows = () => allocationRules.filter(ruleMatches).map(row => `<tr><td>${esc(row.group_activity_code || row.group_activity || 'Any')}<br><small>${esc(row.group_activity_name || '')}</small></td><td>${esc(row.activity_code || row.activity || 'Any')}<br><small>${esc(row.activity_name || '')}</small></td><td>${esc(row.location_code || row.location || 'Any')}<br><small>${esc(row.location_name || '')}</small></td><td>${esc(row.valuation_to)}</td><td>${esc(row.costing_to)}</td><td class="num">${row.priority}</td><td>${row.is_active ? 'Active' : 'Disabled'}</td><td><button class="btn btn-outline mapping-delete" data-id="${row.id}" type="button">Delete</button></td></tr>`).join('') || '<tr><td colspan="8" class="table-empty">No allocation rules match the current filters.</td></tr>';
    const rulesTable = `<div class="mapping-rules-meta"><span id="allocationRulesCount">${allocationRules.length} rules</span><button id="allocationRulesReset" class="btn btn-outline btn-small" type="button">Reset filters</button></div><div class="table-responsive mapping-rules-table"><table class="table-accounting"><thead><tr><th>Group Activity<input class="rule-header-filter" data-rule-filter="group" placeholder="Filter"></th><th>Activity<input class="rule-header-filter" data-rule-filter="activity" placeholder="Filter"></th><th>Location<input class="rule-header-filter" data-rule-filter="location" placeholder="Filter"></th><th>Valuation To<input class="rule-header-filter" data-rule-filter="valuation" placeholder="Filter"></th><th>Costing To<input class="rule-header-filter" data-rule-filter="costing" placeholder="Filter"></th><th>Priority<input class="rule-header-filter" data-rule-filter="priority" placeholder="Filter"></th><th>Status<select class="rule-header-filter" data-rule-filter="status"><option value="all">All</option><option value="active">Active</option><option value="disabled">Disabled</option></select></th><th></th></tr></thead><tbody id="allocationRulesBody">${renderRuleRows()}</tbody></table></div>`;
    const hierarchyRows = hierarchy.rows || [];
    const parentNames = new Map(hierarchyRows.map(row => [row.account_code, row.account_name]));
    const hierarchyByCode = new Map(hierarchyRows.map(row => [row.account_code, row]));
    const hierarchyChildren = new Map();
    hierarchyRows.forEach(row => { if (row.parent_code && hierarchyByCode.has(row.parent_code)) { const children = hierarchyChildren.get(row.parent_code) || []; children.push(row); hierarchyChildren.set(row.parent_code, children); } });
    hierarchyChildren.forEach(children => children.sort((a, b) => a.account_code.localeCompare(b.account_code)));
    const hierarchyFilters = { query: '', role: 'all', type: 'all', report: 'all' };
    const matchesHierarchy = (row, query) => !query || `${row.account_code} ${row.account_name} ${row.parent_code || ''} ${parentNames.get(row.parent_code) || ''}`.toLowerCase().includes(query);
    const matchesFilters = row => { const role = row.children_count > 0 || row.is_parent ? 'parent' : 'child'; return (hierarchyFilters.role === 'all' || hierarchyFilters.role === role) && (hierarchyFilters.type === 'all' || (row.type || 'Unknown') === hierarchyFilters.type) && (hierarchyFilters.report === 'all' || (hierarchyFilters.report === 'included') === row.report_account); };
    const branchMatches = (row, path = new Set()) => matchesHierarchy(row, hierarchyFilters.query) && matchesFilters(row) || (hierarchyChildren.get(row.account_code) || []).some(child => !path.has(child.account_code) && branchMatches(child, new Set([...path, row.account_code])));
    const hierarchyRow = (row, depth, query) => {
        const children = hierarchyChildren.get(row.account_code) || [];
        const hasChildren = children.length > 0;
        const collapsed = state.accountHierarchyCollapsed[row.account_code] && !hierarchyFilters.query;
        const role = hasChildren || row.is_parent ? 'is-parent' : 'is-child';
        const rowHtml = `<tr class="${hasChildren || row.is_parent ? 'hierarchy-parent-row' : 'hierarchy-child-row'}"><td class="hierarchy-account" style="padding-left:${12 + depth * 24}px"><span class="hierarchy-indent">${depth ? '└─ ' : ''}</span>${hasChildren ? `<button type="button" class="hierarchy-toggle" data-account-toggle="${esc(row.account_code)}" aria-label="${collapsed ? 'Expand' : 'Collapse'} ${esc(row.account_name)}">${collapsed ? '+' : '−'}</button>` : '<span class="hierarchy-spacer"></span>'}<strong>${esc(row.account_code)}</strong><small>${esc(row.account_name)}</small></td><td>${esc(row.parent_code || '—')}<br><small>${esc(row.parent_code ? parentNames.get(row.parent_code) || '' : '')}</small></td><td>${esc(row.type || 'Unknown')}</td><td><span class="mapping-status ${role}">${hasChildren || row.is_parent ? 'Parent' : 'Child'}</span>${hasChildren ? `<small class="hierarchy-child-count">${qty(row.children_count)} child${row.children_count === 1 ? '' : 'ren'}</small>` : ''}</td><td><span class="mapping-status ${row.report_account ? 'is-included' : 'is-reference'}">${row.report_account ? 'Report Cost' : 'Reference only'}</span></td><td><small>${esc(row.hierarchy_source || '—')}</small><br><button type="button" class="btn btn-outline btn-small account-details" data-account-code="${esc(row.account_code)}">Details</button></td></tr>`;
        if (collapsed) return rowHtml;
        return rowHtml + children.filter(child => branchMatches(child)).map(child => hierarchyRow(child, depth + 1, hierarchyFilters.query)).join('');
    };
    const renderHierarchyRows = () => {
        hierarchyFilters.query = hierarchyFilters.query.trim().toLowerCase();
        const roots = hierarchyRows.filter(row => !row.parent_code || !hierarchyByCode.has(row.parent_code)).sort((a, b) => a.account_code.localeCompare(b.account_code));
        return roots.filter(row => branchMatches(row)).map(row => hierarchyRow(row, 0, hierarchyFilters.query)).join('') || `<tr><td colspan="6" class="table-empty">No accounts match the current filters.</td></tr>`;
    };
    const hierarchySummary = hierarchy.summary || {};
    const typeOptions = [...new Set(hierarchyRows.map(row => row.type || 'Unknown'))].sort().map(type => `<option value="${esc(type)}">${esc(type)}</option>`).join('');
    $('#pageContent').innerHTML = `${sectionHeader('D365 Allocation Mapping', 'Review the synced account hierarchy and manage allocation rules for Valuation To and Costing To.', '<span class="config-source">D365 Master → Hierarchy → Allocation</span>')}<p class="financial-notice">Main Account code and name come from the synced D365 master. Use the hierarchy tab to validate parent and child structure before maintaining allocation rules.</p><div class="allocation-tabs" role="tablist"><button type="button" class="active" data-allocation-tab="hierarchy">Account Hierarchy</button><button type="button" data-allocation-tab="rules">Allocation Rules</button><button type="button" data-allocation-tab="import">Import &amp; Preview</button></div><section id="allocationHierarchyPanel">${card('D365 Main Account Master & Hierarchy', `<div class="mapping-account-summary"><span><b>${qty(hierarchySummary.total || hierarchyRows.length)}</b> D365 accounts</span><span><b>${qty(hierarchySummary.report_accounts || 0)}</b> Report Cost accounts</span><span><b>${qty(hierarchySummary.parents || 0)}</b> Parent accounts</span><span><b>${qty(hierarchySummary.reference_only || 0)}</b> Reference only</span></div><div class="hierarchy-toolbar"><input id="accountHierarchySearch" type="search" placeholder="Search account code, name, or parent"><select id="accountHierarchyRole"><option value="all">All roles</option><option value="parent">Parent only</option><option value="child">Child only</option></select><select id="accountHierarchyType"><option value="all">All D365 types</option>${typeOptions}</select><select id="accountHierarchyReport"><option value="all">All Report Cost status</option><option value="included">Report Cost only</option><option value="reference">Reference only</option></select><button id="accountHierarchyExpand" class="btn btn-outline" type="button">Expand all</button><button id="accountHierarchyCollapse" class="btn btn-outline" type="button">Collapse all</button><button id="accountHierarchyReset" class="btn btn-outline" type="button">Reset</button></div><div class="hierarchy-toolbar-meta"><span id="accountHierarchyCount" class="integration-note"></span><span class="integration-note">Source: D365 Main Accounts · ${esc(hierarchySummary.fallback_hierarchy || 0)} fallback links</span></div><div class="table-responsive mapping-hierarchy-table"><table class="table-accounting"><thead><tr><th>Account &amp; Name</th><th>Parent Account</th><th>D365 Type</th><th>Role</th><th>Report Cost</th><th>Source / Details</th></tr></thead><tbody id="accountHierarchyBody">${renderHierarchyRows()}</tbody></table></div><div id="accountHierarchyDetails" class="account-hierarchy-details" hidden></div>`)} </section><section id="allocationRulesPanel" hidden>${card('New Allocation Rule', `<form id="mappingForm" class="mapping-form"><input name="account_code" placeholder="Exact account"><input name="account_prefix" placeholder="Account prefix"><input name="group_activity_code" placeholder="Group Activity code"><input name="group_activity_name" placeholder="Group Activity name"><input name="activity_code" placeholder="Activity code"><input name="activity_name" placeholder="Activity name"><input name="location_code" placeholder="Location code"><input name="location_name" placeholder="Location name"><input name="valuation_to" placeholder="Valuation To" required><input name="costing_to" placeholder="Costing To" required><input name="label" placeholder="Rule label"><input name="priority" type="number" value="100" min="0" max="10000"><label><input name="is_active" type="checkbox" checked> Active</label><button class="btn btn-primary" type="submit">Add Mapping</button></form><p id="mappingStatus" class="config-status" aria-live="polite"></p>`)}${card('Current Allocation Rules', rulesTable)}</section><section id="allocationImportPanel" hidden>${card('Import Mapping Master', `<p class="integration-note">Upload XLS, XLSX, CSV, TSV, or pasted text with 8 columns: Group Activity code/name, Activity code/name, Location code/name, Valuation To, Costing To.</p><div class="mapping-import-controls"><input id="mappingMasterFile" type="file" accept=".xls,.xlsx,.csv,.tsv,.txt"><button id="mappingMasterImport" class="btn btn-primary" type="button">Import Mapping Master</button></div><p id="mappingMasterStatus" class="config-status" aria-live="polite"></p>`)}${card('Preview / Import D365 GL Staging', `<div class="mapping-import-controls"><input id="mappingDateFrom" type="date"><input id="mappingDateTo" type="date"><button id="mappingPreview" class="btn btn-outline" type="button">Preview</button><button id="mappingImport" class="btn btn-primary" type="button">Import to GL</button></div><p id="mappingPreviewResult" class="integration-note">Choose a date range or preview all GL staging rows.</p>`)}</section>`;
    const bindHierarchyToggles = () => document.querySelectorAll('[data-account-toggle]').forEach(button => button.addEventListener('click', () => { const code = button.dataset.accountToggle; state.accountHierarchyCollapsed[code] = !state.accountHierarchyCollapsed[code]; $('#accountHierarchyBody').innerHTML = renderHierarchyRows(); bindHierarchyToggles(); bindAccountDetails(); }));
    const bindAccountDetails = () => document.querySelectorAll('.account-details').forEach(button => button.addEventListener('click', () => { const row = hierarchyByCode.get(button.dataset.accountCode); const details = $('#accountHierarchyDetails'); details.hidden = false; details.innerHTML = `<div class="account-detail-heading"><strong data-locale-ignore>${esc(row.account_code)} · ${esc(row.account_name)}</strong><button type="button" class="btn btn-outline" id="closeAccountDetails">Close</button></div><div class="account-detail-grid"><span>Parent<strong data-locale-ignore>${esc(row.parent_code ? `${row.parent_code} · ${parentNames.get(row.parent_code) || ''}` : 'Root account')}</strong></span><span>D365 type<strong>${esc(row.type || 'Unknown')}</strong></span><span>Role<strong>${row.is_parent || row.children_count ? 'Parent' : 'Child'}</strong></span><span>Report Cost<strong>${row.report_account ? 'Included' : 'Reference only'}</strong></span><span>Children<strong>${qty(row.children_count || 0)}</strong></span><span>Hierarchy source<strong>${esc(row.hierarchy_source || '—')}</strong></span><span>Source key<strong>${esc(row.source_key)}</strong></span></div>`; $('#closeAccountDetails').addEventListener('click', () => { details.hidden = true; details.innerHTML = ''; }); }));
    bindHierarchyToggles();
    bindAccountDetails();
    const updateHierarchy = () => { $('#accountHierarchyBody').innerHTML = renderHierarchyRows(); const visible = hierarchyRows.filter(row => branchMatches(row)).length; $('#accountHierarchyCount').textContent = hierarchyFilters.query || hierarchyFilters.role !== 'all' || hierarchyFilters.type !== 'all' || hierarchyFilters.report !== 'all' ? `${visible} accounts in matching hierarchy branches` : `${hierarchyRows.length} accounts loaded`; bindHierarchyToggles(); bindAccountDetails(); };
    $('#accountHierarchySearch').addEventListener('input', event => { hierarchyFilters.query = event.target.value; updateHierarchy(); });
    $('#accountHierarchyRole').addEventListener('change', event => { hierarchyFilters.role = event.target.value; updateHierarchy(); });
    $('#accountHierarchyType').addEventListener('change', event => { hierarchyFilters.type = event.target.value; updateHierarchy(); });
    $('#accountHierarchyReport').addEventListener('change', event => { hierarchyFilters.report = event.target.value; updateHierarchy(); });
    $('#accountHierarchyExpand').addEventListener('click', () => { hierarchyRows.forEach(row => { state.accountHierarchyCollapsed[row.account_code] = false; }); updateHierarchy(); });
    $('#accountHierarchyCollapse').addEventListener('click', () => { hierarchyRows.filter(row => row.children_count > 0).forEach(row => { state.accountHierarchyCollapsed[row.account_code] = true; }); updateHierarchy(); });
    $('#accountHierarchyReset').addEventListener('click', () => { hierarchyFilters.query = ''; hierarchyFilters.role = 'all'; hierarchyFilters.type = 'all'; hierarchyFilters.report = 'all'; state.accountHierarchyCollapsed = {}; $('#accountHierarchySearch').value = ''; $('#accountHierarchyRole').value = 'all'; $('#accountHierarchyType').value = 'all'; $('#accountHierarchyReport').value = 'all'; updateHierarchy(); });
    document.querySelectorAll('[data-allocation-tab]').forEach(button => button.addEventListener('click', () => { document.querySelectorAll('[data-allocation-tab]').forEach(tab => tab.classList.toggle('active', tab === button)); document.querySelector('#allocationHierarchyPanel').hidden = button.dataset.allocationTab !== 'hierarchy'; document.querySelector('#allocationRulesPanel').hidden = button.dataset.allocationTab !== 'rules'; document.querySelector('#allocationImportPanel').hidden = button.dataset.allocationTab !== 'import'; }));
    const bindRuleActions = () => document.querySelectorAll('.mapping-delete').forEach(button => button.addEventListener('click', async () => { await fetch(`/api/d365/mappings/${button.dataset.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } }); await allocationMapping(); }));
    const updateRules = () => { $('#allocationRulesBody').innerHTML = renderRuleRows(); $('#allocationRulesCount').textContent = `${allocationRules.filter(ruleMatches).length} of ${allocationRules.length} rules`; bindRuleActions(); };
    document.querySelectorAll('.rule-header-filter').forEach(input => input.addEventListener('input', event => { ruleFilters[event.target.dataset.ruleFilter] = event.target.value.trim().toLowerCase(); updateRules(); }));
    $('#allocationRulesReset').addEventListener('click', () => { Object.keys(ruleFilters).forEach(key => { ruleFilters[key] = key === 'status' ? 'all' : ''; }); document.querySelectorAll('.rule-header-filter').forEach(input => { input.value = input.dataset.ruleFilter === 'status' ? 'all' : ''; }); updateRules(); });
    $('#mappingForm').addEventListener('submit', async event => { event.preventDefault(); const form = event.currentTarget; const payload = Object.fromEntries(new FormData(form).entries()); payload.is_active = form.is_active.checked; payload.priority = Number(payload.priority || 100); const response = await fetch('/api/d365/mappings', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: JSON.stringify(payload) }); if (!response.ok) { $('#mappingStatus').textContent = 'Could not save mapping.'; return; } $('#mappingStatus').textContent = 'Mapping saved.'; await allocationMapping(); });
    bindRuleActions();
    $('#mappingMasterImport').addEventListener('click', async () => {
        const file = $('#mappingMasterFile').files[0];
        if (!file) { $('#mappingMasterStatus').textContent = 'Choose a mapping file first.'; return; }
        const form = new FormData(); form.append('file', file); $('#mappingMasterStatus').textContent = `Importing ${file.name}…`;
        try {
            const response = await fetch('/api/d365/mappings/import', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: form });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Import failed.');
            $('#mappingMasterStatus').textContent = `Imported ${result.imported} mapping rows${result.skipped ? `, skipped ${result.skipped}` : ''}.`;
            await allocationMapping();
        } catch (error) { $('#mappingMasterStatus').textContent = error.message; }
    });
    const range = () => ({ date_from: $('#mappingDateFrom').value || null, date_to: $('#mappingDateTo').value || null, entity: 'gl_entries' });
    $('#mappingPreview').addEventListener('click', async event => { const button = event.currentTarget; const output = $('#mappingPreviewResult'); button.disabled = true; output.textContent = 'Previewing D365 GL staging…'; try { const result = await postJson('/api/d365/staging/preview', range()); output.textContent = `${result.rows} rows · ${result.mapped} mapped · ${result.unmapped} unmapped · Reporting total ${money(result.total_reporting)}`; } catch (error) { output.textContent = `Preview failed: ${error.message}`; } finally { button.disabled = false; } });
    $('#mappingImport').addEventListener('click', async event => { const button = event.currentTarget; const output = $('#mappingPreviewResult'); button.disabled = true; output.textContent = 'Importing D365 GL staging…'; try { const result = await postJson('/api/d365/staging/import', range()); output.textContent = `Imported ${result.rows} rows · ${result.mapped} mapped · ${result.unmapped} unmapped.`; } catch (error) { output.textContent = `Import failed: ${error.message}`; } finally { button.disabled = false; } });
}

async function viewD365Staging(entity, label, page = 1) {
    const data = await api(`/api/d365/staging?entity=${encodeURIComponent(entity)}&full=1&page=${page}`);
    const pageData = data.rows || {};
    const sourceRows = pageData.data || [];
    const rows = sourceRows.map(row => ({ id: row.id, entity_key: row.entity_key, source_key: row.source_key, company: row.company, account_code: row.account_code, journal_number: row.journal_number, voucher: row.voucher, accounting_date: row.accounting_date, ...(row.payload || {}) }));
    const columns = [...new Set(rows.flatMap(row => Object.keys(row)))];
    const cell = value => typeof value === 'object' && value !== null ? esc(JSON.stringify(value)) : esc(value);
    const body = rows.map(row => `<tr>${columns.map(column => `<td>${cell(row[column])}</td>`).join('')}</tr>`).join('');
    const pager = `<div class="staging-pager"><span>Page ${pageData.current_page || page} of ${pageData.last_page || page} · ${qty(pageData.total || rows.length)} rows</span><div>${pageData.current_page > 1 ? `<button id="stagingPrev" class="btn btn-outline" type="button">Previous</button>` : ''}${pageData.current_page < pageData.last_page ? `<button id="stagingNext" class="btn btn-outline" type="button">Next</button>` : ''}</div></div>`;
    $('#pageContent').innerHTML = `${sectionHeader(`D365 Staging — ${label}`, 'Read-only view of all raw OData fields plus typed staging metadata.', '<button id="backIntegration" class="btn btn-outline" type="button">← Back</button>')}${card(`${badge(entity)} ${qty(pageData.total || rows.length)} rows`, `<div class="table-responsive staging-full-table"><table class="table-accounting"><thead><tr>${columns.map(column => `<th>${esc(column)}</th>`).join('')}</tr></thead><tbody>${body || `<tr><td colspan="${columns.length || 1}">No staging data available.</td></tr>`}</tbody></table></div>${pager}`)}`;
    $('#backIntegration').addEventListener('click', integration);
    $('#stagingPrev')?.addEventListener('click', () => viewD365Staging(entity, label, page - 1));
    $('#stagingNext')?.addEventListener('click', () => viewD365Staging(entity, label, page + 1));
}

async function viewGlData(period, page = 1) {
    const data = await api(`/api/gl-data?period=${encodeURIComponent(period)}&page=${page}&per_page=100`);
    const columns = data.columns || Object.keys(data.rows?.[0] || {});
    const labelOverrides = { main_account_lookup_name: 'Main Account Name', main_account_source_key: 'Main Account Source Key' };
    const labels = columns.map(column => labelOverrides[column] || column.replace(/_/g, ' ').replace(/\b\w/g, character => character.toUpperCase()));
    const numericColumns = new Set(['amount', 'amount_txn', 'amount_idr', 'amount_reporting', 'fuel_quantity']);
    const rows = (data.rows || []).map(row => `<tr>${columns.map(column => `<td class="${numericColumns.has(column) ? 'num' : ''}">${numericColumns.has(column) ? detailMoney(row[column]) : esc(row[column])}</td>`).join('')}</tr>`);
    const pagination = data.pagination || {};
    const pager = `<div class="staging-pager"><span>Page ${pagination.current_page || page} of ${pagination.last_page || 1} · ${qty(pagination.total || data.rows?.length || 0)} rows</span><div>${pagination.current_page > 1 ? '<button id="glPrev" class="btn btn-outline" type="button">Previous</button>' : ''}${pagination.current_page < pagination.last_page ? '<button id="glNext" class="btn btn-outline" type="button">Next</button>' : ''}</div></div>`;
    $('#pageContent').innerHTML = `${sectionHeader('D365 GL Data — Local Snapshot', `Read-only preview of all GL fields for ${period}.`, '<button id="backIntegration" class="btn btn-outline" type="button">← Back</button>')}${card(`${badge('gl_entries')} ${qty(pagination.total || 0)} rows`, `<div class="table-responsive staging-full-table"><table class="table-accounting gl-preview-table"><thead><tr>${labels.map(label => `<th>${esc(label)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.join('') : `<tr><td colspan="${columns.length || 1}">No data available.</td></tr>`}</tbody></table></div>${pager}`)}`;
    $('#backIntegration').addEventListener('click', integration);
    $('#glPrev')?.addEventListener('click', () => viewGlData(period, page - 1));
    $('#glNext')?.addEventListener('click', () => viewGlData(period, page + 1));
}

async function render() {
    const period = $('#period').value;
    const mode = $('#mode').value;
    updateNavigation(period, mode);
    $('#pageContent').innerHTML = '<div class="loading">Loading dashboard data…</div>';
    try {
        if (state.view === 'overview') await overview(period, mode);
        else if (state.view === 'cost') await cost(period, mode);
        else if (state.view === 'glReview') await glTransactionReview(period);
        else if (state.view === 'variance') await variance(period, mode);
        else if (state.view === 'metric') await metric(period, mode);
        else if (state.view === 'equipmentCost') await equipmentCost(period, mode);
        else if (state.view === 'inventory') await inventory(period, mode);
        else if (state.view === 'financial') await financial(period, mode);
        else if (state.view === 'mapping') await allocationMapping();
        else if (state.view === 'config') await configuration();
        else await integration();
    } catch (error) {
        console.error(error);
        $('#pageContent').innerHTML = `<section class="table-card error-card"><h2>Data cannot be loaded</h2><p>${esc(error.message)}</p></section>`;
    }
    applyReadOnlyPageMode();
}

function applyReadOnlyPageMode() {
    if (canEditCurrentPage()) return;
    const writeActions = {
        cost: ['#costClosing', '#costImportGl', '#costDeleteGl'],
        variance: ['.variance-remark-save', '[data-variance-save]'],
        metric: ['[data-db-save]'],
        equipmentCost: ['[data-db-save]'],
        inventory: ['#inventoryManualInputForm button[type="submit"]', '.inventory-input-void'],
        financial: ['.financial-mapping-save'],
        integration: ['#d365SyncAll', '#googleSheetsSync', '#stopD365Progress'],
        mapping: ['#mappingForm button[type="submit"]', '#mappingMasterImport', '#mappingImport', '.mapping-delete'],
        config: ['#configSaveAll'],
    }[state.view] || [];
    writeActions.forEach(selector => document.querySelectorAll(selector).forEach(button => { button.disabled = true; button.title = 'This role has read-only access.'; button.setAttribute('aria-disabled', 'true'); }));
    const readOnlyFields = {
        cost: ['#costClosing'],
        variance: ['[data-variance-remark]'],
        metric: ['[data-db-remark]'],
        equipmentCost: ['[data-db-remark]'],
        inventory: ['#inventoryManualInputForm input', '#inventoryManualInputForm select', '#inventoryManualInputForm textarea'],
        financial: ['.financial-mapping-table select'],
        integration: [],
        mapping: ['#mappingForm input', '#mappingForm select', '#mappingForm textarea', '#mappingMasterFile'],
        config: ['.config-editor input', '.config-editor select', '.config-editor textarea'],
    }[state.view] || [];
    readOnlyFields.forEach(selector => document.querySelectorAll(selector).forEach(field => { field.disabled = true; field.title = 'This role has read-only access.'; }));
    document.querySelectorAll('#pageContent form').forEach(form => {
        if (form.id && ['glReviewFilters', 'costFilters', 'varianceFilters'].includes(form.id)) return;
        if (form.method.toLowerCase() !== 'get') form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; button.title = 'This role has read-only access.'; });
    });
}

document.querySelector('.tabs').addEventListener('click', event => {
    const tab = event.target.closest('[data-view]');
    if (!tab) return;
    event.preventDefault();
    state.view = tab.dataset.view;
    document.body.classList.remove('sidebar-open');
    $('#sidebarToggle').setAttribute('aria-expanded', 'false');
    render();
});
$('#sidebarToggle').addEventListener('click', () => {
    const width = window.innerWidth;
    const mobile = width <= 760;
    const tablet = width > 760 && width <= 1050;
    const expanded = !(mobile
        ? document.body.classList.contains('sidebar-open')
        : tablet
            ? document.body.classList.contains('tablet-sidebar-expanded')
            : !document.body.classList.contains('sidebar-collapsed'));
    if (mobile) document.body.classList.toggle('sidebar-open', expanded);
    else if (tablet) document.body.classList.toggle('tablet-sidebar-expanded', expanded);
    else document.body.classList.toggle('sidebar-collapsed', !expanded);
    $('#sidebarToggle').setAttribute('aria-expanded', String(expanded));
});
$('#sidebarScrim').addEventListener('click', () => { document.body.classList.remove('sidebar-open'); $('#sidebarToggle').setAttribute('aria-expanded', 'false'); });
document.addEventListener('keydown', event => { if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) { document.body.classList.remove('sidebar-open'); $('#sidebarToggle').setAttribute('aria-expanded', 'false'); $('#sidebarToggle').focus(); } });
const sidebarTooltip = document.createElement('div');
sidebarTooltip.id = 'sidebarTooltip';
sidebarTooltip.className = 'sidebar-tooltip';
sidebarTooltip.setAttribute('role', 'tooltip');
sidebarTooltip.hidden = true;
document.body.append(sidebarTooltip);
const sidebarIsCompact = () => document.body.classList.contains('sidebar-collapsed') || (window.innerWidth > 760 && window.innerWidth <= 1050 && !document.body.classList.contains('tablet-sidebar-expanded'));
const showSidebarTooltip = button => {
    if (!sidebarIsCompact()) { sidebarTooltip.hidden = true; return; }
    const label = button.querySelector('.nav-label')?.textContent.trim();
    if (!label) return;
    const rect = button.getBoundingClientRect();
    sidebarTooltip.textContent = label;
    sidebarTooltip.style.left = `${Math.max(8, Math.min(window.innerWidth - 228, rect.left + rect.width / 2 - 110))}px`;
    sidebarTooltip.style.top = `${Math.min(window.innerHeight - 42, rect.bottom + 5)}px`;
    sidebarTooltip.hidden = false;
};
document.querySelectorAll('.app-sidebar [data-view]').forEach(button => {
    button.addEventListener('mouseenter', () => showSidebarTooltip(button));
    button.addEventListener('mouseleave', () => { sidebarTooltip.hidden = true; });
    button.addEventListener('focus', () => showSidebarTooltip(button));
    button.addEventListener('blur', () => { sidebarTooltip.hidden = true; });
});
$('#sidebarToggle').setAttribute('aria-expanded', String(window.innerWidth > 1050));
window.addEventListener('resize', () => {
    document.body.classList.remove('sidebar-open', 'tablet-sidebar-expanded');
    $('#sidebarToggle').setAttribute('aria-expanded', String(window.innerWidth > 1050));
    sidebarTooltip.hidden = true;
});
const shiftPeriod = offset => { const [year, month] = $('#period').value.split('-').map(Number); const date = new Date(Date.UTC(year, month - 1 + offset, 1)); $('#period').value = `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`; render(); };
$('#period').addEventListener('change', render);
$('#periodPrevious').addEventListener('click', () => shiftPeriod(-1));
$('#periodNext').addEventListener('click', () => shiftPeriod(1));
$('#periodCurrent').addEventListener('click', () => { const now = new Date(); $('#period').value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`; render(); });
document.querySelectorAll('.mode-toggle [data-mode]').forEach(button => button.addEventListener('click', () => { $('#mode').value = button.dataset.mode; render(); }));
$('#mode').addEventListener('change', render);
render();
window.addEventListener('finops:locale-change', () => updateNavigation($('#period').value, $('#mode').value));
