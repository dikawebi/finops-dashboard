<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthApiTest extends TestCase
{
    public function test_login_requires_credentials(): void
    {
        $this->postJson('/api/login', [])->assertStatus(422);
    }
}
