<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class GlParserService
{
    public function parseFile(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("GL file not found: {$path}");
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($source)) {
            throw new InvalidArgumentException('GL source must be an array.');
        }

        $rows = [];
        $invalidDates = 0;
        $periods = [];
        foreach ($source as $item) {
            $date = $this->excelDate($item['Date'] ?? null);
            if (!$date) { $invalidDates++; }
            $period = $date ? substr($date, 0, 7) : null;
            if ($period) { $periods[$period] = ($periods[$period] ?? 0) + 1; }
            $rows[] = [
                'journal_number' => $this->text($item['Journal number'] ?? null), 'voucher' => $this->text($item['Voucher'] ?? null),
                'entry_date' => $date, 'year_closed' => $this->text($item['Year closed'] ?? null),
                'account_name' => $this->text($item['AccountName'] ?? null), 'description' => $this->text($item['Description'] ?? null),
                'currency' => $this->text($item['Currency'] ?? null), 'fuel_quantity' => $this->number($item['Fuel quantity'] ?? 0),
                'amount_txn' => $this->number($item['Amount in transaction currency'] ?? null), 'amount_idr' => $this->number($item['Amount IDR'] ?? null),
                'amount' => $this->number($item['Amount'] ?? null), 'amount_reporting' => $this->number($item['Amount in reporting currency'] ?? null),
                'posting_type' => $this->text($item['Posting type'] ?? null), 'posting_layer' => $this->text($item['Posting layer'] ?? null),
                'vendor_account' => $this->text($item['Vendor account'] ?? null), 'customer_account' => $this->text($item['Customer account'] ?? null),
                'invoice_no' => $this->text($item['Invoice no'] ?? null), 'main_account' => $this->text($item['MainAccount'] ?? null),
                'activity' => $this->text($item['Activity'] ?? null), 'cash_flow' => $this->text($item['Cash_Flow'] ?? null),
                'department' => $this->text($item['Departmen'] ?? null), 'group_activity' => $this->text($item['Group_Activity'] ?? null),
                'location' => $this->text($item['Location'] ?? null), 'project' => $this->text($item['Project'] ?? null),
                'project_name' => $this->text($item['Project name'] ?? null), 'cost_center' => $this->text($item['Cost center'] ?? null),
                'valuation_to' => $this->text($item['Valuation to'] ?? null), 'costing_to' => $this->text($item['Costing to'] ?? null),
                'po_dt_number' => $this->text($item['PO DT Number'] ?? null), 'gjae_id' => $this->integer($item['GJAE ID'] ?? null),
                'created_datetime' => $this->datetime($item['Created date and time'] ?? null), 'period' => $period,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        return ['rows' => $rows, 'stats' => ['totalRows' => count($rows), 'invalidDates' => $invalidDates, 'perPeriod' => $periods]];
    }

    private function text(mixed $value): ?string { $value = trim((string) ($value ?? '')); return $value === '' ? null : $value; }
    private function number(mixed $value): ?string { return ($value === null || $value === '') ? null : number_format((float) $value, 6, '.', ''); }
    private function integer(mixed $value): ?int { return ($value === null || $value === '') ? null : (int) $value; }
    private function excelDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return Carbon::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d');
        try { return Carbon::parse((string) $value)->format('Y-m-d'); } catch (\Throwable) { return null; }
    }
    private function datetime(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        try { return is_numeric($value) ? Carbon::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d H:i:s') : Carbon::parse((string) $value)->format('Y-m-d H:i:s'); } catch (\Throwable) { return null; }
    }
}
