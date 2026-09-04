<?php

namespace Tests\Feature\Platform;

use App\Enums\Permission;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use App\Tenancy\SupportAccessPrincipal;

class SupportAccessPrincipalTest extends PlatformTestCase
{
    public function test_an_empty_ability_list_is_refused_rather_than_granted(): void
    {
        $principal = $this->principal();

        $this->assertFalse($principal->can([]));
        $this->assertFalse($principal->canAny([]));
    }

    public function test_a_read_ability_is_granted_and_a_write_ability_is_refused(): void
    {
        $principal = $this->principal();

        $this->assertTrue($principal->can(Permission::ViewAnySale->value));
        $this->assertFalse($principal->can(Permission::CreateSale->value));
    }

    public function test_a_mixed_list_is_refused_because_one_ability_is_a_write(): void
    {
        $principal = $this->principal();

        $this->assertFalse($principal->can([
            Permission::ViewAnySale->value,
            Permission::CreateSale->value,
        ]));
    }

    private function principal(): SupportAccessPrincipal
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();

        return new SupportAccessPrincipal(
            audit: new ShopAccessSession,
            platformUser: $platformUser,
            shop: $shop,
        );
    }
}
