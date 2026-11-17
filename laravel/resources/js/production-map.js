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
    map.insertAdjacentHTML('afterbegin', '<img class="production-landscape" src="/images/production-flow-map-v2.png" alt="North G pit connects by gravel road to BUA and Washing Plant. A longer shared haul road leads to BP Port. ISP is downstream on the same riverbank near the sea; Mother Vessel is offshore." width="1672" height="941"><div class="production-map-heading">Production flow <small>Illustrative geography · select a location for details</small></div>');
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
}
