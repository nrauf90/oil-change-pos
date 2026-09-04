<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Cache\DatabaseLock;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the MySQL row-count semantics that shop provisioning depends on.
 *
 * Illuminate\Cache\DatabaseLock::refresh() renews a lock with
 * `->update(['expiration' => now + seconds]) >= 1`. MySQL counts CHANGED rows
 * by default, so renewing within the same second the lock was taken writes an
 * identical expiration, changes nothing, and reports zero rows — making a lock
 * holder believe it lost a lock it still owns. In production that surfaced as
 * PROVISIONING_LOCK_LOST at the database stage of shop provisioning.
 *
 * The suite runs on SQLite, which already counts matched rows, so the failure
 * is invisible to an ordinary test — and the runtime option is only present
 * when pdo_mysql is loaded. The source assertion below therefore runs
 * everywhere, so removing the option fails the build on a SQLite dev box too.
 */
class MySqlLockRowCountTest extends TestCase
{
    private const MYSQL_FAMILY = ['central', 'mysql', 'mariadb', 'tenant_connection_template'];

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    #[Test]
    public function every_mysql_connection_declares_matched_row_counting(): void
    {
        $source = (string) file_get_contents(config_path('database.php'));

        $this->assertSame(
            count(self::MYSQL_FAMILY),
            substr_count($source, 'PDO::MYSQL_ATTR_FOUND_ROWS'),
            'Each MySQL-backed connection ('.implode(', ', self::MYSQL_FAMILY).') must set '
                .'PDO::MYSQL_ATTR_FOUND_ROWS. Without it DatabaseLock::refresh() silently '
                .'returns false on MySQL and provisioning reports PROVISIONING_LOCK_LOST.',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function mysqlBackedOptions(): array
    {
        return [
            'central' => ['database.connections.central.options'],
            'mysql' => ['database.connections.mysql.options'],
            'mariadb' => ['database.connections.mariadb.options'],
            'tenant template' => ['database.tenant_connection_template.options'],
        ];
    }

    #[Test]
    #[DataProvider('mysqlBackedOptions')]
    public function the_option_is_live_in_config_when_pdo_mysql_is_available(string $configKey): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql not loaded; the option is intentionally absent.');
        }

        $options = config($configKey);

        $this->assertIsArray($options);
        $this->assertArrayHasKey(PDO::MYSQL_ATTR_FOUND_ROWS, $options, $configKey);
        $this->assertTrue($options[PDO::MYSQL_ATTR_FOUND_ROWS]);
    }

    #[Test]
    public function database_lock_still_renews_by_updating_expiration(): void
    {
        // The fix only matters while refresh() renews via an UPDATE whose row
        // count decides success. If Laravel changes that, revisit the config.
        $method = new ReflectionMethod(DatabaseLock::class, 'refresh');
        $lines = (array) file((string) $method->getFileName(), FILE_IGNORE_NEW_LINES);

        $body = implode("\n", array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString('update(', $body);
        $this->assertStringContainsString('expiration', $body);
        $this->assertStringContainsString('>= 1', $body);
    }
}
