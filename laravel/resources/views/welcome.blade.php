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
    <header class="app-header">
        <div class="brand"><img class="logo" src="{{ e($logoPath) }}" alt="{{ e($companyName) }}"><div class="brand-info"><b>{{ $companyName }}</b><span>Accounting &amp; Mining Cost Management System • Local Hosting (Intranet)</span></div></div>
        <div class="header-status"><button class="import" type="button">📤 Import GL Data</button><div class="source-pill"><i></i><span>D365 GL: Online</span></div><div class="source-pill"><i></i><span>Google Sheets: Manual Sync</span></div><div class="period-label">Period:<b id="periodText">2026-10</b></div></div>
    </header>
    <div class="filterbar">
        <label>Month <input id="period" type="month" value="2026-10"></label>
        <label>View <select id="mode"><option value="mtd">MTD</option><option value="ytd">YTD</option></select></label>
        <span id="viewText">MTD — 2026-10</span>
    </div>
    <nav class="tabs" aria-label="Dashboard navigation">
        <button type="button" class="active" data-view="overview">📊 Executive Overview</button>
        <button type="button" data-view="cost">📑 Report Cost (COGP)</button>
        <button type="button" data-view="variance">📈 Cost Variance Analysis</button>
        <button type="button" data-view="metric">⚖️ Cost per Metric Ton</button>
        <button type="button" data-view="inventory">📦 Inventory Valuation (Moving Avg)</button>
        <button type="button" data-view="financial">📑 Financial Statements (FS)</button>
        <button type="button" data-view="integration">🔄 Integration &amp; Data Sources</button>
    </nav>
    <main id="pageContent" class="content" aria-live="polite"></main>
    <footer>{{ $companyName }} • Coal Production Accounting &amp; Cost Dashboard · Data freeze 2026-10-30</footer>
</body>
</html>
