/**
 * P0 — Baseline figures dump untuk standar verifikasi angka (PHPUnit golden test).
 * Target folder: D:\apps\bp-laravel-dashboard\baseline\reference_dump\
 * Period 2026-01 .. 2026-10, mode mtd + ytd (endpoint period-dependent).
 * Endpoint static di-dump sekali.
 *
 * Jalankan: node dump_baseline.js
 */
const fs = require("fs");
const path = require("path");

const BASE = "http://localhost:3000";
const OUT = path.resolve(__dirname, "reference_dump");

// Endpoint yang nilainya tergantung period & mode (heavy)
const PERIOD_ENDPOINTS = [
  "cost-matrix",
  "production-summary",
  "inventory-valuation",
  "cost-per-mt",
  "financial-statements",
  "db-cost-summary",
];

// Endpoint static — di-dump sekali (tanpa period/mode)
const STATIC_ENDPOINTS = [
  "config",
  "accounts",
  "accruals",
  "cost-variance-remarks",
  "excel-cost-example",
];

const PERIODS = [];
for (let m = 1; m <= 10; m++) PERIODS.push(`2026-${String(m).padStart(2, "0")}`);
const MODES = ["mtd", "ytd"];

function classify(body) {
  // JSON string status untuk log ringkas
  return body.success ? "ok" : "ERR:" + (body.error || "?").slice(0, 60);
}

async function fetchOne(url) {
  const t0 = Date.now();
  const res = await fetch(url);
  const body = await res.json();
  return { ms: Date.now() - t0, body };
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const log = [];
  let ok = 0, fail = 0;

  // static
  for (const ep of STATIC_ENDPOINTS) {
    try {
      const { ms, body } = await fetchOne(`${BASE}/api/${ep}`);
      const f = path.join(OUT, `${ep}.static.json`);
      fs.writeFileSync(f, JSON.stringify(body, null, 2));
      log.push(`static  ${ep.padEnd(22)} ${String(ms).padStart(6)}ms  ${classify(body)}`);
      ok++;
    } catch (e) {
      log.push(`static  ${ep.padEnd(22)} FAILED  ${e.message}`);
      fail++;
    }
  }

  // period × mode
  for (const ep of PERIOD_ENDPOINTS) {
    for (const period of PERIODS) {
      for (const mode of MODES) {
        const url = `${BASE}/api/${ep}?period=${period}&mode=${mode}`;
        const label = `${ep.padEnd(22)} ${period} ${mode}`;
        try {
          const { ms, body } = await fetchOne(url);
          const f = path.join(OUT, `${ep}_${period}_${mode}.json`);
          fs.writeFileSync(f, JSON.stringify(body, null, 2));
          log.push(`period  ${label}  ${String(ms).padStart(6)}ms  ${classify(body)}`);
          ok++;
        } catch (e) {
          log.push(`period  ${label}  FAILED  ${e.message}`);
          fail++;
        }
      }
      console.log(`... ${ep} ${period} done`);
    }
  }

  const manifest = {
    capturedAt: new Date().toISOString(),
    base: BASE,
    dataFreezeNote: "Baseline figures dari aplikasi operasional aktif (status data 2026-10-30). Data freeze: 2026-10-30.",
    periods: PERIODS,
    modes: MODES,
    counts: { ok, failed: fail },
  };
  fs.writeFileSync(path.join(OUT, "manifest.json"), JSON.stringify(manifest, null, 2));
  fs.writeFileSync(path.join(OUT, "dump_log.txt"), log.join("\n"));
  console.log(`DONE ok=${ok} failed=${fail} -> ${OUT}`);
})();
