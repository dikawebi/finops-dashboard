# FDD — Functional Design Document
# Development of the PT Borneo Prima Accounting & Mining Cost Dashboard (Laravel)

| Field | Detail |
|---|---|
| **Project Name** | PT Borneo Prima — Accounting & Mining Cost Dashboard |
| **Project Code** | `BP-LARAVEL-DASH` |
| **Document Version** | 1.1 — 30 October 2026 |
| **Status** | DRAFT — PENDING APPROVAL |
| **Business Owner** | Accounting Team |
| **Technical Owner** | IT Team |
| **Reference Documents** | `FORMULA_DASHBOARD.md` (official formula catalog) · `IT_HANDOVER.md` (operations & security) · `LARAVEL_TECHNICAL_PLAN.md` (supporting technical plan) |
| **Derived Document** | This document also serves as the **project tracker** baseline (see section 10, WBS) |

**Framing note:** this plan is the **development of a brand-new system** built from scratch on Laravel 11 — not a migration of an existing system. The formula catalog, allocation rules, screen layout, and data sources already used in daily operations serve as the **specification inputs**, so the new system goes live with accounting behavior the business has already agreed on.

**Key policy decisions:**
1. The account master (163 accounts from `cost_matrix_definition.json`) is implemented **as a database table** `cost_accounts` — no runtime dependency on any JSON file.
2. **The layout follows the operating dashboard design** (7 tabs) — users need no retraining.
3. **Branding identical to the asset-tagging project**: primary `#0066FF`, Plus Jakarta Sans font, PT Borneo Prima `logo-bp.svg` logo, slate palette — visually validated already (see Appendix A).

---

## 1. Purpose & Background

PT Borneo Prima needs an accounting dashboard that unifies GL data (Microsoft Dynamics 365), production metrics (Google Sheets), inventory (moving average), and financial statements into one internal web application. The system is **built from scratch** on Laravel:

1. **Controlled accuracy** — all calculations follow the official formula catalog (`FORMULA_DASHBOARD.md`): COGP + Direct Expense allocation rules, inventory moving average, P&L/Balance Sheet mapping, virtual accrual journals.
2. **Modern architecture** — Laravel 11 (PHP 8.2+), MySQL, MVC structure, ORM, validation, and built-in testing; adopting the same ecosystem as the company's asset-tagging project.
3. **Data integrity** — all transactions & master data live in MySQL (not files), with GL import auditability.
4. **Simple internal deployment** — target shared hosting / internal server, no authentication in the initial release (owner's decision; security handled at the internal network/LAN level).

**Guiding principles:**
- Report figures must be **verified against baseline figures** from the running process (target difference = 0).
- **The layout follows the approved design** — tab, table, and form structures match the operating mockup.
- Initial data (GL, production metrics, financial baseline, inventory balances) is loaded once from official operational data on the data-freeze date.

---

## 2. Scope

### 2.1 In-Scope

| # | Module | Description |
|---|---|---|
| F1 | Executive Overview | 6 KPI cards, cost allocation, top cost drivers, daily production chart |
| F2 | Report Cost (COGP) | 163-account matrix, COGP groups + Direct Expense, search/filter, expand-collapse, CSV export, GL deletion |
| F3 | Cost Variance Analysis | 2-month comparison per account + category, saved remarks |
| F4 | Cost per Metric Ton | 6 stages (Mining, 3 Hauling routes, WP, Port, GA, Selling), monthly comparison (ABC/COGP), DB cost summary |
| F5 | Inventory Valuation | Moving average across 9 locations, opening balance edit, composition chart, ledger & history |
| F6 | Financial Statements | P&L + Balance Sheet from the financial baseline + running GL, inventory reconciliation |
| F7 | Integration Center | D365 & Google Sheets status, sync buttons, GL import (Excel/CSV/paste), accrual input, last 15 tickets |

### 2.2 Out-of-Scope (initial release)

- Authentication / authorization / login (roadmap: next release, see IT_HANDOVER §3)
- Full audit trail & multi-user locking
- Advanced cash flow reports & budget variance (no data source yet)
- Write-back to Google Sheets
- D365 live posting (data pull only)

### 2.3 Contract Standardization (API & Parameters)

- URLs, parameters (`period=YYYY-MM`, `mode=mtd|ytd`), payloads, and API responses are standardized in this design (section 5) and **frozen** as the contract, so the frontend, reporting, and future integrations stay consistent.

---

## 3. Visual Design & Branding (matching asset-tagging)

Brand tokens taken from `D:\apps\asset-tagging`:

| Token | Value | Source |
|---|---|---|
| Primary | `#0066FF` | `AdministratorPanelProvider` `Color::hex('#0066FF')` |
| Logo | `logo-bp.svg` | asset-tagging `public/images/logo-bp.svg` |
| Font | Plus Jakarta Sans (400–800) | `->font('Plus Jakarta Sans')` + bunny.net |
| Primary text | `#0F172A` | brand-logo blade |
| Borders / lines | `#e2e8f0` | styles in login-concepts |
| App background | `#f1f5f9` | body token |
| Dark panel | `#0F172A` | login side panel |
| Brand subtitle | uppercase, `0.08em` tracking, primary color | brand-logo blade |
| Success / Danger | `#16A34A` / `#dc2626` | common tokens |
| Radius | cards 8–20px, pills 999px | asset-tagging consistency |

**Branding contract:**
- Header: BP logo + "PT BORNEO PRIMA" + subtitle "Accounting & Mining Cost Dashboard".
- Footer: "PT Borneo Prima • Coal Production Accounting & Cost Dashboard".
- All primary actions (primary buttons, active tabs, links) use `#0066FF`.
- **Layout & components (card grid, accounting tables, modals) follow the approved operating mockup;** the currently running application (with branding applied) becomes the official visual reference — see Appendix A.
- Data source badges (`.source-badge`) kept as a living-documentation feature.

> Status: the branding tokens above have been applied to the active operating mockup (`public/styles.css`, `public/index.html`, `public/images/logo-bp.svg`) for visual approval before development; the same reference files are used when building the Laravel frontend.

---

## 4. Architecture & Data Sources

### 4.1 Architecture

```
Browser (Blade + vanilla app.js, layout per approved design)
        │  fetch JSON (API contract §5)
        ▼
Laravel 11 (routing, validation, controllers)
        │
        ├── Services/  (calculation implementations per FORMULA_DASHBOARD.md)
        │      CostMatrixService, InventoryValuationService, CostPerMtService,
        │      FinancialStatementService, ProductionService, GlParserService,
        │      AccrualService, SheetsProductionParser, SheetsDbParser
        │
        ├── MySQL (14 tables — transactions & master data)
        │
        └── storage/app/data/ (read-only JSON: history/audit, analysis snapshots)
```

### 4.2 Data Map: DB vs JSON vs Input

| No | Artifact | Storage | Input Source | Data Direction |
|---|---|---|---|---|
| 1 | GL transactions | **DB** `gl_entries` | Excel upload (column L mandatory) / CSV-TSV-paste / D365 OData sync | Replace only the imported months (upsert); accruals & history unaffected |
| 2 | Monthly production metrics (DB sheet) | **DB** `monthly_metrics` | Google Sheets sync (DB) | Merge by period |
| 3 | Daily production (Produksi sheet) | **DB** `daily_production` | Google Sheets sync (Produksi, column S = Coal Getting) | Merge by date |
| 4 | DB Cost Summary / activities | **DB** `db_cost_summary` | Google Sheets sync | Merge by entity-activity |
| 5 | Dashboard Excel, ABC Monthly, Calc COGP-COGS (analysis snapshots) | Read-only JSON `storage/app/data/` | Google Sheets sync (optional; may fail → keep last snapshot) | Replace snapshot |
| 6 | Master of 163 accounts (hierarchy) | **DB** `cost_accounts` | One-time initial load from the official COA master (BALANCE AMOUNTS always come from GL; the master holds only names/hierarchy) | Read-only |
| 7 | Inventory opening balances (9 locations) | **DB** `inventory_openings` | "First Period Opening Balance" form (`POST /api/inventory-opening`) | Upsert, recalculation of following months |
| 8 | Inventory movements | **DB** `inventory_movements` | Calculated per month from GL + sheets; dummy movements via ledger input | Recalculated per month |
| 9 | Financial baseline | **DB** `financial_baselines` | One-time initial load from the official financial workbook | Read-only |
| 10 | System configuration | **DB** `system_configs` | Config form `POST /api/config` (D365 credentials, period) | Update; secrets in `.env` only |
| 11 | Manual accruals | **DB** `accruals` | Accrual form `POST /api/accruals` (amount = qty × rate) | Insert/delete |
| 12 | Automatic accruals (BLC rent, WP rent, Land/Road rent) | **DB** `accruals` with `source='db'` | Computed from production metrics during sync; history snapshot → read-only JSON | Inserted during sync |
| 13 | Variance & DB cost remarks | **DB** `cost_variance_remarks`, `db_cost_remarks` | User UI (max 2000 chars) | Upsert by key |
| 14 | Mining capitalization & adjustment history | Read-only JSON | Jan–Sep workbook (reference) | Read-only |

**Explicit decision:** the account master (163 accounts) goes into the **database** (table `cost_accounts`) — chosen because it is consumed by many endpoints (`/api/accounts`, Report Cost, accrual dropdown) and avoids file reads on every request.

### 4.3 Database Tables (14)

`gl_entries`, `gl_import_batches`, `monthly_metrics`, `daily_production`, `db_cost_summary`, `system_configs`, `financial_baselines`, `inventory_openings`, `inventory_movements`, `accruals`, `accrual_history`, `cost_accounts`, `cost_variance_remarks`, `db_cost_remarks`.

Key rules: `gl_entries.period` as `YYYY-MM` (indexed), amount as `DECIMAL(19,6)`, and a GL import **only replaces the imported months** (monthly upsert; no duplicates allowed).

### 4.4 Initial Data Load

| Data | Origin | Method |
|---|---|---|
| GL history | GL export from D365 (Excel) | Artisan import command (column-L parser for "Amount in reporting currency"; full USD amounts, no conversion) |
| Production & daily metrics | Operational Google Sheets | Initial sync via `/api/sync/sheets` |
| Financial baseline | Official accounting workbook | Seeder / one-time artisan loader |
| Inventory balances & account master | Official master data | One-time seeders |

---

## 5. API Contract

| Endpoint | Method | Data Source |
|---|---|---|
| `/api/config` | GET/POST | `system_configs` |
| `/api/cost-matrix` | GET | `gl_entries` + `cost_accounts` + `accruals` + history JSON |
| `/api/production-summary` | GET | `monthly_metrics` + `daily_production` + `db_cost_summary` |
| `/api/inventory-valuation` | GET | all inventory tables + `gl_entries` |
| `/api/inventory-opening` | POST | `inventory_openings` |
| `/api/cost-per-mt` | GET | cost matrix + production |
| `/api/financial-statements` | GET | `financial_baselines` + `gl_entries` + `accruals` |
| `/api/upload-gl`, `/api/upload-gl-excel` | POST | parser → `gl_entries` (+ `gl_import_batches`) |
| `/api/gl-data` | DELETE | reset `gl_entries` |
| `/api/accounts` | GET | `cost_accounts` (heads 5–9, non-parent) |
| `/api/accruals` | GET/POST/DELETE | `accruals` |
| `/api/sync/sheets`, `/api/sync/d365` | POST | Google Sheets parsers / D365 OData |
| `/api/db-cost-summary`, `/api/excel-cost-example` | GET | `db_cost_summary` + JSON snapshots |
| `/api/cost-variance-remarks`, `/api/db-cost-summary/remark` | GET/POST | remarks tables |

All `period=YYYY-MM` and `mode=mtd|ytd` parameters stay consistent; validation and error messages are standardized in this contract.

---

## 6. Non-Functional Requirements

| Aspect | Spec |
|---|---|
| Target browsers | Modern Chromium/Edge, modern Firefox |
| Responsiveness | Desktop-first; mobile not required |
| UI language | English; supporting documents in Indonesian |
| Display precision | Full USD amounts, no decimals, thousand separators; 3-decimal rates on screen; internal calculations keep full precision |
| Performance | Page load under 3 s at ~20k GL rows; GL import of 5k rows under 60 s |
| Logging | Laravel log; GL import auditability in `gl_import_batches` |

---

## 7. Acceptance Criteria

### 7.1 Numerical Verification (mandatory pass)

1. **Baseline figures** are collected from the running process (accounting workbook + GL D365) for periods 2026-01 … 2026-10 in MTD and YTD modes → stored in `reference_dump/` as the verification basis.
2. The system must produce identical values (tolerance = 0) for: `/api/cost-matrix`, `/api/inventory-valuation`, `/api/cost-per-mt`, `/api/financial-statements`, `/api/production-summary`, `/api/db-cost-summary` (timestamp fields ignored).
3. Unit tests per formula (PHPUnit composed from the `FORMULA_DASHBOARD.md` catalog): account-head distribution (7–9→GA, 5–6 following Costing to / Valuation to), hauling routes, Direct Expense, inventory moving average, P&L/Balance Sheet mapping with the virtual accrual journal (Dr/Cr 1530003) — all pass.
4. The known reconciliation gap of **USD 3,001** (October balance sheet, per IT_HANDOVER §6) must appear with exactly the same figure — proof of a faithful, consistent implementation of the rules rather than a silently discarded difference.

### 7.2 Functional UAT (user scenarios)

| UAT No | Scenario | Expectation |
|---|---|---|
| UAT-01 | Upload `sample_gl_dynamics365.xlsx` | Row count & sum per account = baseline |
| UAT-02 | CSV import for 2 months | Only those 2 months replaced; other months in the DB untouched |
| UAT-03 | TSV paste + local decimal comma (1.234,56), "(19,166)" | Converted faithfully without value change |
| UAT-04 | Empty/invalid file import | Rejected with an error message; DB undamaged |
| UAT-05 | Add manual accrual | Amount appears exactly in Report Cost & FS; virtual journal Dr/Cr 1530003 in FS |
| UAT-06 | Delete accrual | Report Cost & FS recalculated |
| UAT-07 | Edit opening balance | All following months recalculated; charts & cards updated |
| UAT-08 | GL deletion | Balances reset; workbook history & accruals remain |
| UAT-09 | Google Sheets sync | Metrics & daily updated, automatic accruals created, history snapshot stored |
| UAT-10 | Save variance & DB cost remarks | Values persist after reload |
| UAT-11 | Report Cost CSV export | 163 accounts (including zero-balance accounts) with grand total |
| UAT-12 | Search/expand/filter Report Cost | Per the approved design |
| UAT-13 | Mode switch MTD↔YTD | All tabs respond correctly |
| UAT-14 | Hard refresh Ctrl+F5 | No regression |

### 7.3 Branding acceptance

- Header/footer use the BP logo + "PT Borneo Prima" + blue uppercase subtitle.
- Primary color `#0066FF` consistent on active tabs, primary buttons, and KPI cost cards.
- Side-by-side screenshots vs asset-tagging attached during UAT (see Appendix A).

---

## 8. Risks & Mitigations

| # | Risk | Impact | Mitigation | Owner |
|---|---|---|---|---|
| R1 | Numeric precision (PHP float) | Report figures drift | Mandatory bcmath/DECIMAL(19,6); UAT-01..07 | Dev |
| R2 | Allocation-rule complexity (heads 7-9→GA, hauling routes, Not Valuated→GA, Direct Expense) | Wrong COGP allocation | Unit test per formula from the catalog + baseline verification | Dev |
| R3 | Excel column-L parser interpretation differences | Wrong imports | Unit tests on real files + per-account sum comparison | Dev |
| R4 | Shared hosting limits (timeout/memory/composer) | Deployment failure | Early pre-check; vendor built locally; chunk inserts | IT |
| R5 | Google Sheets sharing / IP blocking | Sync failure | Verify access before phase 6; fallback: manual CSV import | IT |
| R6 | Operational data changes during development | Baseline figures expire | Data freeze on a fixed date; scheduled baseline refresh; label every verification | Dev+Acc |
| R7 | Credential leak (D365 secret) | Data leak | Secrets only in `.env`, never in code/repo; rotate the secret after go-live | IT |

---

## 9. Estimates & Timeline (in working days)

### 9.1 Estimate per Phase

| Phase | Duration (working days) | Summary |
|---|---|---|
| P0 Preparation | 0.5 d | Finalize FDD + collect baseline figures & initial operational data |
| P1 Foundation | 1.5 d | Laravel project + 14 migrations/seeders + basic branding |
| P2 Data Layer | 2.5 d | Master seeders + GL importer + initial data load |
| P3 Engine | 4 d | Implement 6 calculation services per FORMULA_DASHBOARD.md (figures = baseline) |
| P4 API | 1.5 d | 18 endpoints per the §5 contract |
| P5 Frontend | 2.5 d | Blade + assets + final branding (approved layout) |
| P6 Sheets Sync | 1.5 d | 5-sheet parser + sync endpoint |
| P7 Verification & UAT | 2.5 d | Figures vs baseline verification + UAT-01..14 |
| P8 Deployment | 1.5 d | Shared hosting + go-live |
| **Total** | **18 working days** | (2 devs: 10–12 working days) |

### 9.2 Calendar Estimates

| Scenario | Effort | Effective Duration | Calendar Duration (±15% buffer) |
|---|---|---|---|
| 1 developer (full focus) | 18 working days | 18 working days | **± 4.5 weeks** (5 weeks with buffer) |
| 2 developers (parallel P3 & P5/P6) | 10–12 working days | 11 working days | **± 2.5–3 weeks** |
| 1 developer (part-time, ½ effort) | 18 working days | 36 working days | ± 9 weeks (not recommended) |

> Start date follows the FDD approval date (decision D5 — data freeze). Each working day = Monday–Friday, excluding national holidays.

### 9.3 Milestones (offset from project start, 1-dev scenario)

| Milestone | Phase | Target Day | Pass Criteria |
|---|---|---|---|
| **M1** | End of P2 | **Day 4.5** | Operational data loaded into the DB & visible on the dashboard — demo before continuing to the engine |
| **M2** | End of P3 | **Day 8.5** | All report figures = baseline figures — most critical gate |
| **M3** | End of P7 | **Day 16.5** | Zero-diff verification + completed UAT — **GO/NO-GO** |
| **M4** | End of P8 | **Day 18** | Live on shared hosting |

---

## 10. WBS — Project Tracker Baseline

The status column (`— / 🔄 / ✅`) is updated daily. This structure can be quoted directly into Jira/Trello/Notion.

| WBS | Task | Depends | Owner | Est (working days) | Status |
|---|---|---|---|---|---|
| 0.1 | Finalize FDD + collect baseline figures (`reference_dump/`) | – | Dev+Acc | 0.3 | — |
| 0.2 | Backup/snapshot operational data (GL export, sheets) for the initial load | 0.1 | Dev | 0.2 | — |
| 1.1 | `composer create-project` Laravel 11 | 0.1 | Dev | 0.3 | — |
| 1.2 | Migrations for 14 tables (+ indexes) | 1.1 | Dev | 0.7 | — |
| 1.3 | Eloquent models + relations | 1.2 | Dev | 0.3 | — |
| 1.4 | .env configuration (cache file, sync queue) | 1.1 | Dev | 0.2 | — |
| 1.5 | Branding setup #0066FF + font + logo (visual validation) | – | Dev+UI | 0.2 | **✅** |
| 2.1 | `CostAccountSeeder` (163 accounts → `cost_accounts`) | 1.3 | Dev | 0.4 | — |
| 2.2 | Baseline seeders (financial, inventory opening, history files) | 1.3 | Dev | 0.5 | — |
| 2.3 | `GlParserService` (cleanNumeric + delimiter + xlsx column L) | 1.3 | Dev | 0.8 | — |
| 2.4 | `/api/upload-gl` + `/api/upload-gl-excel` + batch log | 2.3 | Dev | 0.6 | — |
| 2.5 | Initial data load (GL, production metrics) via artisan command | 2.4 | Dev | 0.3 | — |
| 3.1 | `CostMatrixService` (COGP + Direct Expense rules) | 2.5 | Dev | 1.2 | — |
| 3.2 | `InventoryValuationService` (moving average, 9 locations) | 3.1 | Dev | 1.2 | — |
| 3.3 | `AccrualService` (manual + auto rentals) | 3.1 | Dev | 0.5 | — |
| 3.4 | `ProductionService` + DB sheet parser | 2.5 | Dev | 0.6 | — |
| 3.5 | `CostPerMtService` (6 stages) | 3.1,3.4 | Dev | 0.3 | — |
| 3.6 | `FinancialStatementService` (P&L + Balance Sheet + virtual accrual journal) | 3.1,3.3 | Dev | 1.0 | — |
| 3.7 | PHPUnit tests per formula + verification vs baseline figures | 3.1–3.6 | Dev | 0.7 | — |
| 4.1 | Routing + controllers + form request validation (18 endpoints) | 3.7 | Dev | 1.0 | — |
| 4.2 | Standardized error/exception contract per §5 | 4.1 | Dev | 0.3 | — |
| 5.1 | Blade layout + 7 tab partials (approved design layout) | 1.5,4.1 | Dev | 1.2 | — |
| 5.2 | Frontend assets (app.js, styles.css, data source badges) | 5.1 | Dev | 0.8 | — |
| 5.3 | Manual UI testing of all tabs | 5.2 | Dev+Acc | 0.5 | — |
| 6.1 | `SheetsProductionParser` + `SheetsDbParser` (gviz) | 3.4 | Dev | 0.7 | — |
| 6.2 | `/api/sync/sheets` (5 sheets + snapshot fallback + automatic accruals) | 6.1 | Dev | 0.6 | — |
| 6.3 | `/api/sync/d365` OData connector | – | Dev | 0.3 | — |
| 7.1 | Automated verification vs baseline figures (periods 01-10, MTD/YTD) | 5.3,6.2 | Dev | 1.0 | — |
| 7.2 | UAT-01..14 per section 7.2 | 7.1 | Acc+Dev | 1.0 | — |
| 7.3 | Branding comparison screenshots | 5.3 | UI | 0.2 | — |
| 8.1 | Shared-hosting pre-check (PHP ext, composer, outbound HTTPS) | – | IT | 0.3 | — |
| 8.2 | Deploy + migrate + seed + .env | 7.2 | IT | 0.5 | — |
| 8.3 | Go-live smoke test | 8.2 | IT | 0.3 | — |
| | **Total estimate** | | | **≈19 working days** | |

**Milestone checkpoints:**
- **M1** (end of P2): "Operational data loaded & displayed" — demo before proceeding to the engine.
- **M2** (end of P3): "All figures = baseline figures" — **most critical gate**.
- **M3** (end of P7): "Zero-diff verification + completed UAT" — **GO/NO-GO**.
- **M4** (end of P8): "Live on shared hosting".

---

## 11. Decisions Required from the Owner (for FDD approval)

| # | Decision | Options | Approved (fill in) |
|---|---|---|---|
| D1 | Estimate 18 working days (1 dev) / 10-12 working days (2 devs), WBS total ≈19 days | Accept / revise scope | |
| D2 | Authentication deferred to a later release (per the initial decision) | Accept / must-have now | |
| D3 | Branding matching asset-tagging (#0066FF + Jakarta Sans + BP logo) | Accept / revise | |
| D4 | Shared hosting: confirm PHP 8.2+, MySQL, outbound HTTPS | Confirm availability | |
| D5 | Data-freeze date & baseline figure source (periods 2026-01..2026-10) | Set the date | |
| D6 | D365 sync is pull-only (manual status/sync button, no live posting) | Accept | |

**Approval sign-off:**

| Role | Name | Signature | Date |
|---|---|---|---|
| Business Owner (Accounting) | ___________ | ___________ | ____ |
| Technical Owner (IT) | ___________ | ___________ | ____ |
| Project Manager | ___________ | ___________ | ____ |

---

*Appendices: visual-reference dashboard screenshots (Appendix A), `LARAVEL_TECHNICAL_PLAN.md` (supporting technical plan), `FORMULA_DASHBOARD.md` (official formulas), `IT_HANDOVER.md` (operations & security).*
