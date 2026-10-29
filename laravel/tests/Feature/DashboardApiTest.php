<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    public function test_dashboard_routes_validate_period_and_mode(): void
    {
        $this->getJson('/api/cost-matrix?period=bad')->assertStatus(422);
        $this->getJson('/api/cost-matrix?period=2026-10&mode=bad')->assertStatus(422);
        $this->getJson('/api/inventory-valuation?period=2026-13')->assertStatus(422);
    }

    public function test_dashboard_data_contracts_are_available_for_each_tab(): void
    {
        $this->getJson('/api/cost-matrix?period=2026-10&mode=mtd')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['period', 'mode', 'rows', 'grandTotal']]);

        $this->getJson('/api/financial-statements?period=2026-10&mode=mtd')
            ->assertOk()
            ->assertJsonStructure(['data' => ['sections', 'incomeStatement']]);

        $this->getJson('/api/cost-per-mt?period=2026-10&mode=mtd')
            ->assertOk()
            ->assertJsonStructure(['data' => ['stages']]);

        $this->getJson('/api/cost-variance?period=2026-10&mode=mtd')
            ->assertOk()
            ->assertJsonStructure(['data' => ['current', 'previous', 'rows']]);

        $this->getJson('/api/integration-status')
            ->assertOk()
            ->assertJsonStructure(['data' => ['d365', 'googleSheets', 'database', 'dataFreeze']]);
    }
}
