import '../css/production-map.css';
// Geographic presentation only: quantities and costs are supplied by the overview.
export function mountProductionMap(panel, { card, buaStock, stockLine, siteBreakdown, flowSitesAvailable }) {
    const map = panel.querySelector('.overview-flow-map');
    map.classList.add('production-geography');
    map.querySelectorAll('.overview-bypass, .overview-vessel-direct, .overview-scene').forEach(node => node.remove());
    const locations = map.querySelector('.overview-flow-cards');
    locations.insertAdjacentHTML('beforeend', card('Stockpile BUA', '', 'Closing inventory', [['Closing stock', stockLine(buaStock)]], stockLine(buaStock)));
    ['Takalot', 'North G'].forEach(name => {
        const value = flowSitesAvailable ? siteBreakdown.find(row => row[0] === name)?.[1] : null;
        locations.insertAdjacentHTML('beforeend', card(`Pit ${name}`, '', 'Coal getting · selected period', [['Coal Getting', value || 'Location detail unavailable for this period']], value || '—'));
    });
    map.insertAdjacentHTML('afterbegin', '<img class="production-landscape" src="/images/production-flow-map.png" alt="Two pits connect by short gravel roads to BUA and Washing Plant. A longer haul road leads to BP Port. ISP is downstream on the same riverbank near the sea; Mother Vessel is offshore." width="1672" height="941"><div class="production-map-heading">Production flow <small>Illustrative geography · select a location for details</small></div>');
    const routes = document.createElement('div');
    routes.className = 'production-route-strip';
    map.querySelectorAll('.overview-route').forEach(route => routes.append(route));
    map.append(routes);
}
