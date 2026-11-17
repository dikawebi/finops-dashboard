import '../css/production-map.css';
// Geographic presentation only: quantities and costs are supplied by the overview.
export function mountProductionMap(panel, options) {
    const { card, buaStock, stockLine, siteBreakdown, flowSitesAvailable } = options;
    const map = panel.querySelector('.overview-flow-map');
    map.classList.add('production-geography');
    map.querySelectorAll('.overview-bypass, .overview-vessel-direct, .overview-scene').forEach(node => node.remove());
    const locations = map.querySelector('.overview-flow-cards');
    const [buaQuantity, buaRate = ''] = stockLine(buaStock).split(' · ');
    locations.insertAdjacentHTML('beforeend', card('Stockpile BUA', buaRate, 'Closing inventory', [['Closing stock', stockLine(buaStock)]], buaQuantity));
    locations.querySelector('[data-overview-card="Coal Mining"]').hidden = true;
    ['North G'].forEach(name => {
        const value = flowSitesAvailable ? siteBreakdown.find(row => row[0] === name)?.[1] : null;
        locations.insertAdjacentHTML('beforeend', card(`Pit ${name}`, '', 'Coal getting · selected period', [['Coal Getting', value || 'Location detail unavailable for this period']], value || '—'));
    });
    const metricLabels = { 'Coal Mining': 'Coal getting', 'Pit Takalot': 'Coal getting', 'Pit North G': 'Coal getting', 'Stockpile BUA': 'Closing stock', 'Washing Plant': 'Clean coal', 'BP Port': 'Closing stock', 'ISP': 'Closing stock', 'Mother Vessel': 'Sales volume' };
    locations.querySelectorAll('[data-overview-card]').forEach(item => {
        item.querySelector('.overview-scene')?.remove();
        const label = document.createElement('span');
        label.className = 'production-metric-label';
        label.textContent = metricLabels[item.dataset.overviewCard];
        item.querySelector('.overview-card-values').prepend(label);
    });
    panel.querySelector('.overview-flow-detail').setAttribute('aria-live', 'polite');
    map.insertAdjacentHTML('afterbegin', '<img class="production-landscape" src="/images/production-flow-map-v5.png" alt="North G pit connects by gravel road to BUA and Washing Plant. A longer shared haul road leads to BP Port. ISP is downstream on the same riverbank near the sea; Mother Vessel is offshore." width="1672" height="941"><div class="production-map-heading">Production flow <small>Illustrative geography · select a location for details</small></div>');
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
    map.insertAdjacentHTML('beforeend', '<svg class="production-shipping" viewBox="0 0 1672 941" aria-label="Direct shipping from BP Port to Mother Vessel"><defs><marker id="production-ship-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0L10 5L0 10z" fill="#75e7ff"/></marker></defs><path d="M970 628 C1080 655 1170 570 1295 527 S1480 436 1530 370" fill="none" stroke="#75e7ff" stroke-width="4" stroke-dasharray="12 9" marker-end="url(#production-ship-arrow)"/><text x="1260" y="565" fill="#fff" stroke="#153442" stroke-width="4" paint-order="stroke" font-size="18" text-anchor="middle">BP Port → Mother Vessel</text></svg>');
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
    const matchingDetails = async item => {
        const { stages, qty, number, period, mode, dailyData, api } = options;
        const stock = id => stages.find(stage => stage.id === id);
        const rowQty = (stage, test) => stage ? (stage.rows || []).filter(test).reduce((sum, row) => sum + Number(row.qtyMT || 0), 0) : null;
        const mt = value => value == null ? '—' : `${qty(value)} MT`;
        const closing = stage => stage ? `${mt(stage.endingQtyMT)} · ${stage.endingQtyMT ? `USD ${number(stage.endingValueUSD / stage.endingQtyMT)} / MT` : '—'}` : '—';
        if (item.dataset.overviewCard === 'Washing Plant') {
            const raw = stock('nonWash'), clean = stock('washed'), ash = stock('highAsh');
            const feed = rowQty(raw, row => row.type === 'outflow' && (row.source === 'washed' || row.label === 'Washing Plant Feed'));
            const reject = rowQty(clean, row => String(row.label || '').endsWith('Stock Reject (qty only)') || row.label === 'Reject (quantity only)');
            return { title: 'Washing Plant — Details', rows: [
                ['Raw coal available', mt(raw?.availableQtyMT)], ['Feed to washing', mt(feed == null ? null : -feed)],
                ['Clean coal produced · feed less reject', mt(feed == null || reject == null ? null : -feed + reject)],
                ['High ash received', mt(rowQty(ash, row => row.type === 'receipt' && ['washed reject','washed'].includes(row.source)))],
                ['Clean coal hauled to Port', mt(clean ? -rowQty(clean, row => row.type === 'outflow' && (['port','transitWpPort'].includes(row.source) || row.label === 'Loaded to BP PORT')) : null)],
                ['Ending raw coal', closing(raw)], ['Ending clean coal', closing(clean)], ['Ending high ash', closing(ash)]
            ], note: `${mode.toUpperCase()} movements; available raw coal and ending balances are for ${period}. Closing balances include opening stock, adjustments and other transfers. High ash follows recorded inventory transfers.` };
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
            return [entity, !volume ? '—' : `${mt(volume)} · ${cost == null ? 'USD — / MT' : `USD ${number(cost / volume)} / MT`}`];
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
        popup.append(heading, dismiss, content);
        const currentRevision = ++revision;
        matchingDetails(item).then(result => {
            if (!result || active !== item || revision !== currentRevision) return;
            heading.textContent = result.title;
            content.replaceChildren();
            result.rows.forEach(([name, value]) => {
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
