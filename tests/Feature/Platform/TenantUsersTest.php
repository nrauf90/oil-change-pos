<?php

namespace Tests\Feature\Platform;

use App\Actions\Tenancy\CollectTenantUsers;
use App\Actions\Tenancy\ProvisionShop;
use App\Data\ProvisionShopData;
use App\Enums\Role;
use App\Filament\Platform\Resources\Shops\Pages\ViewShop;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\User;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Livewire\Livewire;
use LogicException;

class TenantUsersTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
    }

    public function test_it_lists_only_the_selected_shops_staff(): void
    {
        $selected = $this->provisionShop('selected-shop');
        $other = $this->provisionShop('other-shop');

        resolve(TenantConnectionManager::class)->within($selected, static function (): void {
            User::factory()->create(['username' => 'counter1', 'name' => 'Counter One']);
        });

        resolve(TenantConnectionManager::class)->within($other, static function (): void {
            User::factory()->create(['username' => 'somebody-else', 'name' => 'Other Shop Staff']);
        });

        $collected = resolve(CollectTenantUsers::class)->handle($selected);
        $usernames = array_column($collected['users'], 'username');

        $this->assertContains('counter1', $usernames);
        $this->assertContains('selected-shop-owner', $usernames);
        // A platform screen reading the wrong shop's database is the failure
        // this whole tenancy layer exists to prevent.
        $this->assertNotContains('somebody-else', $usernames);
    }

    public function test_it_never_returns_anything_that_could_authenticate_someone(): void
    {
        $shop = $this->provisionShop('credential-shop');

        $collected = resolve(CollectTenantUsers::class)->handle($shop);

        $this->assertNotSame([], $collected['users']);

        foreach ($collected['users'] as $user) {
            $this->assertArrayNotHasKey('password', $user);
            $this->assertArrayNotHasKey('remember_token', $user);
            $this->assertArrayNotHasKey('id', $user);
        }
    }

    public function test_it_reports_inactive_staff_rather_than_hiding_them(): void
    {
        $shop = $this->provisionShop('mixed-shop');

        resolve(TenantConnectionManager::class)->within($shop, static function (): void {
            User::factory()->inactive()->create(['username' => 'dismissed']);
        });

        $collected = resolve(CollectTenantUsers::class)->handle($shop);
        $byUsername = array_column($collected['users'], null, 'username');

        // Someone auditing who can reach a shop needs to see a disabled account
        // exists at all; silently omitting it hides a re-enablable way in.
        $this->assertArrayHasKey('dismissed', $byUsername);
        $this->assertFalse($byUsername['dismissed']['is_active']);
        $this->assertTrue($byUsername['mixed-shop-owner']['is_active']);
    }

    public function test_it_reports_each_staff_members_roles(): void
    {
        $shop = $this->provisionShop('role-shop');

        $collected = resolve(CollectTenantUsers::class)->handle($shop);
        $byUsername = array_column($collected['users'], null, 'username');

        $this->assertStringContainsString(
            Role::Admin->value,
            $byUsername['role-shop-owner']['roles'],
        );
    }

    public function test_it_counts_every_staff_member_even_when_the_list_is_capped(): void
    {
        $shop = $this->provisionShop('counting-shop');

        resolve(TenantConnectionManager::class)->within($shop, static function (): void {
            User::factory()->count(3)->create();
        });

        $collected = resolve(CollectTenantUsers::class)->handle($shop);

        // owner + 3
        $this->assertSame(4, $collected['total']);
        $this->assertSame(4, $collected['shown']);
        $this->assertCount(4, $collected['users']);
    }

    public function test_it_releases_the_tenant_connection_afterwards(): void
    {
        $shop = $this->provisionShop('release-shop');

        resolve(CollectTenantUsers::class)->handle($shop);

        // A platform request that leaves a tenant connection open hands the next
        // one somebody else's database.
        $this->assertFalse(resolve(TenantContext::class)->initialized());
    }

    public function test_it_refuses_to_run_inside_an_existing_tenant_context(): void
    {
        $shop = $this->provisionShop('nested-shop');

        resolve(TenantConnectionManager::class)->within($shop, function () use ($shop): void {
            $this->expectException(LogicException::class);

            resolve(CollectTenantUsers::class)->handle($shop);
        });
    }

    public function test_the_shop_view_renders_the_staff_section(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->provisionShop('rendered-shop');

        resolve(TenantConnectionManager::class)->within($shop, static function (): void {
            User::factory()->create(['username' => 'visible-staff', 'name' => 'Visible Staff']);
        });

        // RepeatableEntry fed from an array rather than a relationship is the
        // part that breaks at render time, not in the action.
        Livewire::actingAs($platformUser, 'platform')
            ->test(ViewShop::class, ['record' => $shop->getKey()])
            ->assertOk()
            ->assertSee('visible-staff')
            ->assertSee('rendered-shop-owner');
    }

    public function test_the_staff_section_explains_itself_when_the_shop_cannot_be_read(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->provisionShop('unreadable-shop');

        // Delete the tenant database out from under the page.
        $path = rtrim((string) config('database.tenant_sqlite_root'), '/\\')
            .DIRECTORY_SEPARATOR.'unreadable-shop.sqlite';
        @unlink($path);

        // An operator staring at an empty list has to be told which empty it is.
        Livewire::actingAs($platformUser, 'platform')
            ->test(ViewShop::class, ['record' => $shop->getKey()])
            ->assertOk()
            ->assertSee('could not be read');
    }

    private function provisionShop(string $slug, string $timezone = 'Asia/Karachi'): Shop
    {
        $database = rtrim((string) config('database.tenant_sqlite_root'), '/\\')
            .DIRECTORY_SEPARATOR."{$slug}.sqlite";

        return resolve(ProvisionShop::class)->handle(new ProvisionShopData(
            name: str($slug)->replace('-', ' ')->title()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $database,
            ownerName: 'Test Owner',
            ownerUsername: "{$slug}-owner",
            ownerEmail: "{$slug}@example.test",
            temporaryOwnerPassword: 'temporary-owner-password',
            timezone: $timezone,
        ));
    }
}
