<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_system_role_and_module_only_names_catalogued_permissions(): void
    {
        $codes = PermissionCatalog::codes();
        $this->assertSame($codes, array_values(array_unique($codes)));
        foreach (PermissionCatalog::SYSTEM_ROLES as $role => $permissions) {
            $this->assertSame([], array_diff($permissions === ['*'] ? [] : $permissions, $codes), "Role {$role} names an unknown permission.");
        }
        foreach (PermissionCatalog::MODULES as $module => $definition) {
            $this->assertSame([], array_diff($definition['permissions'], $codes), "Module {$module} names an unknown permission.");
        }
        $this->assertNotContains('supplier.portal', PermissionCatalog::templateCodes('tenant_administrator'));
    }
}
