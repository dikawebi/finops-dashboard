<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FinOps Dashboard — PT Borneo Prima</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="app-header">
        <img class="logo" src="/images/logo-bp.svg" alt="BP">
        <div class="brand"><b>PT BORNEO PRIMA</b><span>ACCOUNTING &amp; MINING COST DASHBOARD • LOCAL HOSTING (INTRANET)</span></div>
        <button class="import" type="button">📥 Import GL<br><small>Data</small></button>
        <div class="source-pill"><i></i>D365 GL:<br><span>Online</span></div>
        <div class="source-pill"><i></i>Google Sheets:<br><span>Manual Sync</span></div>
        <div class="period-label">Period:<b id="periodText">2026-10</b></div>
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
    <footer>FinOps-Dashboard — PT Borneo Prima · Data freeze 2026-10-30</footer>
</body>
</html>
