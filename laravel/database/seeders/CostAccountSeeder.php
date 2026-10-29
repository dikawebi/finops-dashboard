<?php

namespace Database\Seeders;

use App\Models\CostAccount;
use Illuminate\Database\Seeder;

class CostAccountSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('../data_snapshot/extracted/cost_matrix_definition.json');
        foreach (json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) as $item) {
            CostAccount::updateOrCreate(['account_code' => (string) $item['code']], ['account_name' => $item['name'], 'parent_code' => $item['parent'] ?? null, 'is_parent' => (bool) ($item['isParent'] ?? false), 'head_account' => substr((string) $item['code'], 0, 1)]);
        }
    }
}
