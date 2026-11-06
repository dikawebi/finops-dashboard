<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($system = \App\Models\SystemConfig::get('system', []))
    @php($companyName = $system['companyName'] ?? 'FinOps Dashboard')
    @php($logoPath = $system['logoPath'] ?? '/images/logo-bp.svg')
    <title>{{ $companyName }} — Coal Mining Accounting &amp; Cost Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .content { max-width: 1550px; margin: 0 auto; }
        .overview-kpi-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .data-warning { margin: 0; padding: 12px 16px; background: #fff8e8; border-bottom: 1px solid #f1d28b; color: #8a5a16; font-size: 12px; }
        .grid-2 { display: grid; grid-template-columns: 1.05fr .95fr; gap: 18px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 9px; box-shadow: 0 2px 5px #dbe4ef40; overflow: hidden; padding: 16px; }
        .card .card-title { padding: 0 0 12px; border-bottom: 1px solid var(--line); }
        .allocation-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 14px 0 20px; }
        .allocation-grid article { border: 1px solid; border-radius: 7px; padding: 14px; min-height: 84px; }
        .allocation-val { border-color: #08a6c4; background: #e9fbfd; color: #075c73; }
        .allocation-cost { border-color: #e2a01e; background: #fff8e8; color: #8a3d0b; }
        .allocation-grid b { display: block; font-size: 11px; letter-spacing: .03em; }
        .allocation-grid strong { display: block; margin: 8px 0; font-size: 20px; }
        .allocation-grid span { display: block; font-size: 11px; line-height: 1.45; }
        .drivers-title { display: block; margin-bottom: 10px; font-size: 12px; }
        .driver-row { display: grid; grid-template-columns: 1fr auto; align-items: end; gap: 12px; margin: 9px 0; font-size: 11px; }
        .driver-row > div { min-width: 0; }
        .driver-row span { display: block; margin-bottom: 5px; }
        .driver-track { height: 7px; border-radius: 8px; background: #eef2f8; overflow: hidden; }
        .driver-row i { display: block; height: 7px; border-radius: 8px; background: #203e8d; }
        .driver-row b { white-space: nowrap; }
        .chart-footer { display: flex; justify-content: space-between; gap: 12px; margin-top: 18px; padding-top: 12px; border-top: 1px solid var(--line); color: var(--muted); font-size: 11px; }
        @media (max-width: 1050px) { .overview-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 1050px) { .grid-2 { grid-template-columns: 1fr; } }
        @media (max-width: 640px) { .overview-kpi-grid, .allocation-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body data-company-name="{{ e($companyName) }}">
    <div class="app-shell">
        <div id="sidebarScrim" class="sidebar-scrim" aria-hidden="true"></div>
        <aside id="appSidebar" class="app-sidebar" aria-label="Main navigation">
            <div class="sidebar-brand"><img class="logo" src="{{ e($logoPath) }}" alt=""><div><b>{{ $companyName }}</b><span>FINANCE &amp; OPERATIONS</span></div></div>
            <nav class="tabs" aria-label="Dashboard navigation">
                <span class="nav-group-label">OVERVIEW</span>
                <button type="button" class="active" data-view="overview"><span class="nav-icon">📊</span><span class="nav-label">Executive Overview</span></button>
                <span class="nav-group-label">COST &amp; REPORTS</span>
                <button type="button" data-view="cost"><span class="nav-icon">📑</span><span class="nav-label">Report Cost (COGP)</span></button>
                <button type="button" data-view="variance"><span class="nav-icon">📈</span><span class="nav-label">Cost Variance Analysis</span></button>
                <button type="button" data-view="metric"><span class="nav-icon">⚖️</span><span class="nav-label">Cost per Metric Ton</span></button>
                <button type="button" data-view="inventory"><span class="nav-icon">📦</span><span class="nav-label">Inventory Valuation</span></button>
                <button type="button" data-view="financial"><span class="nav-icon">📑</span><span class="nav-label">Financial Statements</span></button>
                <span class="nav-group-label">TRANSACTIONS</span>
                <button type="button" data-view="glReview"><span class="nav-icon">🧾</span><span class="nav-label">GL Transaction Review</span></button>
                <span class="nav-group-label">DATA &amp; CONFIGURATION</span>
                <button type="button" data-view="integration"><span class="nav-icon">🔄</span><span class="nav-label">Integration &amp; Data Sources</span></button>
                <button type="button" data-view="mapping"><span class="nav-icon">🧭</span><span class="nav-label">Allocation Mapping</span></button>
                <button type="button" data-view="config"><span class="nav-icon">⚙️</span><span class="nav-label">Configuration</span></button>
            </nav>
            <div class="sidebar-company"><b>{{ $companyName }}</b><span>Coal Production Accounting</span></div>
        </aside>
        <div class="app-main">
            <div class="filterbar" aria-label="Reporting period controls">
                <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="true"><span></span><span></span><span></span></button>
                <div class="period-control-group"><span class="filterbar-label">Period</span><div class="period-picker"><button id="periodPrevious" class="period-step" type="button" aria-label="Previous month">‹</button><input id="period" type="month" value="2026-10" aria-label="Selected reporting month"><button id="periodNext" class="period-step" type="button" aria-label="Next month">›</button></div><button id="periodCurrent" class="period-current" type="button">Current month</button></div>
                <div class="mode-control-group"><span class="filterbar-label">View</span><div class="mode-toggle" role="group" aria-label="Reporting view"><button type="button" class="active" data-mode="mtd">MTD</button><button type="button" data-mode="ytd">YTD</button></div><select id="mode" class="visually-hidden" tabindex="-1" aria-hidden="true"><option value="mtd">MTD</option><option value="ytd">YTD</option></select></div>
                <span id="viewText" class="active-period">MTD · October 2026</span>
            </div>
            <main id="pageContent" class="content" aria-live="polite"></main>
            <footer>{{ $companyName }} • Coal Production Accounting &amp; Cost Dashboard · Data freeze 2026-10-30</footer>
        </div>
    </div>
</body>
</html>
