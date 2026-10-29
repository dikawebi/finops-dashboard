<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FinOps-Dashboard — PT Borneo Prima</title>

    <!-- Font: Plus Jakarta Sans (bunny.net) — branding sama dengan asset-tagging -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

    <style>
        :root {
            --primary: #0066FF;
            --primary-soft: #E8F1FF;
            --text-main: #0F172A;
            --text-muted: #64748B;
            --bg: #f1f5f9;
            --surface: #ffffff;
            --border: #e2e8f0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif;
            background: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header — pola acuan screenshot 01_overview.png */
        header.app-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 14px 32px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        header.app-header img.logo { height: 40px; width: auto; }
        .brand-title {
            font-size: 17px;
            font-weight: 800;
            letter-spacing: 0.04em;
            color: var(--text-main);
        }
        .brand-subtitle {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--primary);
            margin-top: 1px;
        }

        main { flex: 1; max-width: 960px; width: 100%; margin: 0 auto; padding: 40px 24px; }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 16px;
        }
        .card h2 { font-size: 15px; font-weight: 700; margin-bottom: 8px; }
        .card p { font-size: 13px; color: var(--text-muted); line-height: 1.7; }
        .badge {
            display: inline-block;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border-radius: 999px;
            padding: 4px 12px;
            margin-bottom: 12px;
        }

        .checks { list-style: none; margin-top: 10px; }
        .checks li {
            font-size: 13px;
            color: var(--text-main);
            padding: 6px 0;
            border-bottom: 1px dashed var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checks li:last-child { border-bottom: none; }
        .checks .ok {
            color: var(--primary);
            font-weight: 700;
            width: 20px;
            text-align: center;
        }
        .checks .muted { color: var(--text-muted); font-size: 12px; margin-left: auto; }

        footer {
            text-align: center;
            padding: 18px;
            font-size: 12px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <header class="app-header">
        <img class="logo" src="/images/logo-bp.svg" alt="Borneo Prima">
        <div>
            <div class="brand-title">PT BORNEO PRIMA</div>
            <div class="brand-subtitle">Accounting &amp; Mining Cost Dashboard</div>
        </div>
    </header>

    <main>
        <div class="card">
            <span class="badge">P1 Fondasi — Selesai</span>
            <h2>FinOps-Dashboard (BP-LARAVEL-DASH)</h2>
            <p>
                Sistem ini dibangun dari nol dengan Laravel {{ Illuminate\Foundation\Application::VERSION }}
                (PHP v{{ PHP_VERSION }}), sesuai FDD dan angka baseline di
                <code>baseline/reference_dump/</code>. Placeholder ini diganti dashboard
                pada tahap P5.
            </p>
        </div>

        <div class="card">
            <h2>Status Fondasi</h2>
            <ul class="checks">
                <li><span class="ok">✓</span> Laravel + MySQL <code>finops_dashboard</code> <span class="muted">P1.1</span></li>
                <li><span class="ok">✓</span> Migration 14 tabel dashboard <span class="muted">P1.2</span></li>
                <li><span class="ok">✓</span> Model Eloquent (14) terverifikasi <span class="muted">P1.3</span></li>
                <li><span class="ok">✓</span> Branding dasar (font, warna, header) <span class="muted">P1.4</span></li>
                <li><span class="ok">→</span> Data layer: seeder &amp; parser GL <span class="muted">P2</span></li>
            </ul>
        </div>
    </main>

    <footer>FinOps-Dashboard — dibangun dari nol, diverifikasi 1:1 terhadap baseline figures</footer>
</body>
</html>
