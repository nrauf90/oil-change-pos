<?php

namespace Tests\Feature;

use Tests\TestCase;

class TenantsProvisionCommandTest extends TestCase
{
    public function test_provision_command_exposes_only_the_protected_secret_file_input(): void
    {
        $this->artisan('tenants:provision --help')
            ->expectsOutputToContain('Central shop UUID or exact slug')
            ->expectsOutputToContain('--owner-password-file')
            ->doesntExpectOutputToContain('--password')
            ->assertSuccessful();
    }
}
