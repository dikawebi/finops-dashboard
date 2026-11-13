<?php

namespace Database\Seeders;

use App\Models\FinancialAccountMapping;
use Illuminate\Database\Seeder;
use RuntimeException;

class FinancialAccountMappingSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seed-data/financial_mapping.json');
        if (!is_file($path)) throw new RuntimeException('Financial mapping seed file is missing.');
        $accounts = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['accounts'] ?? [];
        $now = now();
        foreach ($accounts as $code => $mapping) {
            FinancialAccountMapping::updateOrCreate(['account_code' => (string) $code], [
                'account_name' => $mapping['name'] ?? null,
                'row_id' => $mapping['rowId'],
                'source_label' => $mapping['sourceLabel'] ?? null,
                'reference_sep' => $mapping['referenceSep'] ?? null,
                'reference_oct' => $mapping['referenceOct'] ?? null,
                'updated_at' => $now,
            ]);
        }
    }
}
