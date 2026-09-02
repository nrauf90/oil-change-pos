<?php

namespace Tests\Unit\Tenancy;

use App\Tenancy\Provisioning\LaravelMySqlServerConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MySqlServerConnectionTest extends TestCase
{
    public function test_it_binds_existence_values_and_delegates_only_validated_create_identifiers(): void
    {
        $database = 'Tenant_01';
        $connection = $this->createMock(Connection::class);
        $schema = $this->createMock(Builder::class);
        $closed = 0;

        $connection->expects($this->once())
            ->method('selectOne')
            ->with(
                $this->callback(static fn (string $sql): bool => ! str_contains($sql, $database)
                    && str_contains($sql, 'SCHEMA_NAME = ?')),
                [$database],
            )
            ->willReturn((object) ['SCHEMA_NAME' => $database]);
        $connection->expects($this->once())
            ->method('getSchemaBuilder')
            ->willReturn($schema);
        $schema->expects($this->once())
            ->method('createDatabase')
            ->with($database);

        $server = new LaravelMySqlServerConnection(
            $connection,
            function () use (&$closed): void {
                $closed++;
            },
        );

        $this->assertTrue($server->databaseExists($database));
        $server->createDatabase($database);
        $server->close();
        $server->close();
        $this->assertSame(1, $closed);
    }

    public function test_it_rejects_an_unvalidated_identifier_before_schema_ddl(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('getSchemaBuilder');
        $server = new LaravelMySqlServerConnection($connection, static function (): void {});

        $this->expectException(InvalidArgumentException::class);

        $server->createDatabase('tenant`; DROP DATABASE central;--');
    }

    public function test_laravel_grammar_quotes_the_validated_identifier_without_if_not_exists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConfig')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                default => null,
            },
        );
        $sql = (new MySqlGrammar($connection))->compileCreateDatabase('Tenant_01');

        $this->assertSame(
            'create database `Tenant_01` default character set `utf8mb4` default collate `utf8mb4_unicode_ci`',
            $sql,
        );
        $this->assertStringNotContainsStringIgnoringCase('if not exists', $sql);
    }
}
