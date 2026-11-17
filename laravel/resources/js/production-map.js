import '../css/production-map.css';
// Geographic presentation only: quantities and costs are supplied by the overview.
export function mountProductionMap(panel, { card, buaStock, stockLine, siteBreakdown, flowSitesAvailable }) {
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
    map.insertAdjacentHTML('afterbegin', '<img class="production-landscape" src="/images/production-flow-map-v4.png" alt="North G pit connects by gravel road to BUA and Washing Plant. A longer shared haul road leads to BP Port. ISP is downstream on the same riverbank near the sea; Mother Vessel is offshore." width="1672" height="941"><div class="production-map-heading">Production flow <small>Illustrative geography · select a location for details</small></div>');
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
    attachMapPopover(panel, map);
}

function attachMapPopover(panel, map) {
    const popup = panel.querySelector('.overview-flow-detail');
    popup.hidden = false;
    popup.setAttribute('popover', 'auto');
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-label', 'Production details');
    popup.id = 'production-location-detail';
    popup.classList.add('production-detail-popover');
    let active = null, pinned = false, timer, listeners;
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
