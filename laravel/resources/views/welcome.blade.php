<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($system = \App\Models\SystemConfig::get('system', []))
    @php($companyName = $system['companyName'] ?? 'FinOps Dashboard')
    @php($logoPath = $system['logoPath'] ?? '/images/logo-bp.svg')
    <title>{{ $companyName }} — Coal Mining Accounting &amp; Cost Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .content { max-width: 1550px; margin: 0 auto; }
        .overview-kpi-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .data-warning { margin: 0; padding: 12px 16px; background: #fff8e8; border-bottom: 1px solid #f1d28b; color: #8a5a16; font-size: 12px; }
        @media (max-width: 1050px) { .overview-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 640px) { .overview-kpi-grid { grid-template-columns: 1fr; } }
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
        <a href="#" class="active" data-view="overview">📊 Executive Overview</a>
        <a href="#" data-view="cost">📑 Report Cost (COGP)</a>
        <a href="#" data-view="variance">📈 Cost Variance Analysis</a>
        <a href="#" data-view="metric">⚖️ Cost per Metric Ton</a>
        <a href="#" data-view="inventory">📦 Inventory Valuation (Moving Avg)</a>
        <a href="#" data-view="financial">📑 Financial Statements (FS)</a>
        <a href="#" data-view="integration">🔄 Integration &amp; Data Sources</a>
    </nav>
    <main id="pageContent" class="content" aria-live="polite"></main>
    <footer>{{ $companyName }} • Coal Production Accounting &amp; Cost Dashboard · Data freeze 2026-10-30</footer>
</body>
</html>
