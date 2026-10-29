<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthApiTest extends TestCase
{
    public function test_login_requires_credentials(): void
    {
        $this->postJson('/api/login', [])->assertStatus(422);
    }

    public function test_mutating_accrual_route_requires_authentication(): void
    {
        $this->postJson('/api/accruals', ['period'=>'2026-10','account_code'=>'5000051','qty'=>1,'rate_usd'=>1])->assertUnauthorized();
    }
}
