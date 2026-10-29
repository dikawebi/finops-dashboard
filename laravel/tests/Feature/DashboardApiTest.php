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
}
