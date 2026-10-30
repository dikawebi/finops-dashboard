# Remaining Visual Parity Work

Reference: `D:\apps\mining-accounting-dashboard\public\index.html` and `public\styles.css`.

This checklist records the remaining differences between the Laravel dashboard and the reference. Authorization is intentionally deferred. Multipart GL import and external configuration activation are deferred per the project direction; configuration and D365 staging APIs already exist.

## Executive Overview

- Compare the KPI card dimensions, source labels, spacing, and values against the reference at the same viewport.
- Match the allocation card colors, explanatory copy, Top Cost Drivers rows, and daily production chart sizing/axes/labels to the reference.
- Ensure the daily chart displays the same selected-period production records as the reference source. The Laravel API currently uses `daily_production` rows and reports no records when that table is empty.
- Match the DB production card visibility and missing-period notes to the reference behavior.

## Report Cost (COGP)

- The grouped, two-row COGP/Direct Expense header and grand-total footer are present, but column labels and each cell mapping still need a field-by-field comparison with the reference matrix.
- Implement working account search, Show All Accounts, parent-only, and hide-zero controls against the existing Cost Matrix response.
- Match the reference account hierarchy, check values, source notes, route warnings, table count, and sticky total behavior.
- Multipart GL import, export, and delete actions are outside this visual-parity pass; import/configuration remain deferred.

## Cost Variance Analysis

- Implement the reference location/category filter, account search, and hide-zero behavior.
- Connect the existing variance remark response/persistence workflow to the matching reference row controls; current inputs are placeholders.
- Match the previous/current month headings, variance colors, totals/footer, table spacing, and empty-state behavior.

## Cost per Metric Ton

- Match stage cards and the detailed table columns to the reference, including valuation cost, costing cost, total cost, operating volume, stage cost/MT, and total production cost/MT.
- Recreate the monthly comparison tables for location COGP, activity-based cost, and counterpart breakdown using source data only.
- Recreate DB Cost Summary by Activity controls and table using the existing `/api/db-cost-summary` response; do not synthesize missing comparison values.

## Inventory Valuation (Moving Avg)

- Compare donut dimensions, segment colors/order, center labels, and the location legend with the reference.
- Match location card layout and the complete 14-column movement ledger, including adjustment fields and transfer rate.
- Add the hauling-route summary, BUA monthly history, stockpile flow cards, and closing-balance history with the reference ordering and labels.
- Verify opening/closing values and included/excluded stockpile totals against the source response.

## Financial Statements (FS)

- Replace the generic two-column presentation with the reference P&L and Balance Sheet table structures and period headers.
- Add the reference balances-by-account detail table and match heading/total row formatting.
- Reconcile the displayed values against the reference financial baseline. Current financial API `incomeStatement` is provisional and contains placeholder zero values in some fields; do not present those as final figures.

## Integration & Data Sources

- Match connector cards, source labels, detail rows, status badges, and spacing to the reference.
- Display latest production records only when the underlying records exist; the current daily production table contains date and tonnage fields and does not provide ticket/vehicle details.
- Keep sync and accrual controls clearly aligned with available backend workflows. Authorization remains deferred; do not imply a live external D365 or Sheets connection when using local snapshots.

## Final Visual QA

- Compare all seven tabs with the reference at matching desktop and mobile viewport sizes.
- Check tab navigation, selected period/mode behavior, overflow, empty data, and number formatting.
- Recheck actual browser screenshots when local-origin browser access is available; source-code comparison alone does not establish pixel parity.

## Release verification completed

- `php artisan test`: 7 tests and 34 assertions passed.
- `php artisan dashboard:regression --suite=all`: all frozen Cost Matrix, Cost/MT, Financial Statements, and Inventory baselines passed.
- `npm run build`: production Vite build passed.
