# Remaining Visual Parity Work

Reference: `D:\apps\mining-accounting-dashboard\public\index.html` and `public\styles.css`.

This checklist records the remaining differences between the Laravel dashboard and the reference. Authorization is intentionally deferred. Multipart GL import and external configuration activation are deferred per the project direction; configuration and D365 staging APIs already exist.

## Executive Overview

- Implemented: KPI cards, allocation cards, Top Cost Drivers, production DB table, daily SVG chart, source notes, and missing-data messaging.
- Remaining: compare spacing, dimensions, and chart sizing at matching desktop/mobile viewports; production chart structure and no-data handling now match the reference.

## Report Cost (COGP)

- Implemented: grouped two-row COGP/Direct Expense header, grand-total footer, account search, Show All Accounts, parent-only, hide-zero, account hierarchy collapse, export, source notes, route warnings, and table count.
- Remaining: field-by-field visual comparison of column widths, colors, and sticky total behavior.
- Multipart GL import, export, and delete actions are outside this visual-parity pass; import/configuration remain deferred.

## Cost Variance Analysis

- Implemented: location/category filter, category filter, account search, hide-zero behavior, previous/current headings, variance colors, totals/footer, export, and empty state.
- Remaining: remark editing/persistence is deferred with authorization/CSRF.

## Cost per Metric Ton

- Implemented: stage cards, detailed cost table, monthly performance comparisons, activity/counterpart tables, and DB Cost Summary controls/table from `/api/db-cost-summary`.
- Remaining: compare exact row ordering and spacing against the reference at matching viewport sizes.

## Inventory Valuation (Moving Avg)

- Implemented: SVG donut, location legend, location cards, 14-column movement ledger, hauling-route summary, BUA monthly history, stockpile flow cards, and closing-balance history.
- Remaining: compare exact segment order, card spacing, and table dimensions against a browser screenshot; donut fallback labels and legend sizing now match the reference.

## Financial Statements (FS)

- Implemented: P&L and Balance Sheet period tables, selected-period highlighting, GL source labels, diagnostics, and balances-by-account detail.
- Remaining: business reconciliation of diagnostics and browser comparison of header/total row styling.

## Integration & Data Sources

- Implemented: connector cards, source labels, status details, D365 staging entity table, sync progress panel, GL preview entry point, accrual display, and latest production records when present.
- Remaining: browser comparison of connector spacing and labels. External sync remains explicitly represented as configured or local snapshot based on API status.

## Final Visual QA

- Compare all seven tabs with the reference at matching desktop and mobile viewport sizes.
- Check tab navigation, selected period/mode behavior, overflow, empty data, and number formatting.
- Recheck actual browser screenshots when local-origin browser access is available; source-code comparison alone does not establish pixel parity.

## Release verification completed

- `php artisan test`: 7 tests and 34 assertions passed.
- `php artisan dashboard:regression --suite=all`: all frozen Cost Matrix, Cost/MT, Financial Statements, and Inventory baselines passed.
- `npm run build`: production Vite build passed.
