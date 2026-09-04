<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'central'),

    /*
     * Containment root for tenant SQLite files. Deliberately narrower than
     * database_path(): the central control-plane database lives directly in
     * database/, and widening this to the whole directory puts it inside the
     * root that tenant targets are validated against. Adopting a legacy
     * database that lives elsewhere means setting TENANT_SQLITE_ROOT for that
     * run, which keeps the widening explicit and temporary.
     */
    'tenant_sqlite_root' => env('TENANT_SQLITE_ROOT', database_path('tenants')),

    'tenant_sqlite_provisioning_root' => env(
        'TENANT_SQLITE_PROVISIONING_ROOT',
        database_path('tenants'),
    ),

    'tenant_attestation_lock_path' => env(
        'TENANT_ATTESTATION_LOCK_PATH',
        storage_path('framework/tenant-attestation-locks'),
    ),

    'tenant_provisioning_lock_seconds' => env('TENANT_PROVISIONING_LOCK_SECONDS', 900),

    'tenant_mysql_remote_provisioning_enabled' => env(
        'TENANT_MYSQL_REMOTE_PROVISIONING_ENABLED',
        false,
    ),

    'tenant_connection_template' => [
        'driver' => env('TENANT_DB_CONNECTION', 'sqlite'),
        'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('TENANT_DB_PORT', env('DB_PORT', '3306')),
        'database' => env('TENANT_DB_DATABASE', env('DB_DATABASE', database_path('database.sqlite'))),
        'username' => env('TENANT_DB_USERNAME', env('DB_USERNAME', 'root')),
        'password' => env('TENANT_DB_PASSWORD', env('DB_PASSWORD', '')),
        'unix_socket' => env('TENANT_DB_SOCKET', env('DB_SOCKET', '')),
        'charset' => env('TENANT_DB_CHARSET', env('DB_CHARSET', 'utf8mb4')),
        'collation' => env('TENANT_DB_COLLATION', env('DB_COLLATION', 'utf8mb4_unicode_ci')),
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'foreign_key_constraints' => env('TENANT_DB_FOREIGN_KEYS', env('DB_FOREIGN_KEYS', true)),
        'options' => extension_loaded('pdo_mysql') ? array_filter([
            PDO::MYSQL_ATTR_SSL_CA => env('TENANT_MYSQL_ATTR_SSL_CA', env('MYSQL_ATTR_SSL_CA')),
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => env('TENANT_MYSQL_VERIFY_SERVER_CERT', true),
        ], static fn (mixed $value): bool => $value !== null) + [
            // Matched-row UPDATE counts — see the note on the central
            // connection. Keeps a MySQL-backed tenant behaving like the
            // SQLite the application is tested against.
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
        ] : [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'central' => [
            'driver' => env('CENTRAL_DB_CONNECTION', 'sqlite'),
            'url' => env('CENTRAL_DB_URL'),
            'host' => env('CENTRAL_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('CENTRAL_DB_PORT', env('DB_PORT', '3306')),
            'database' => env('CENTRAL_DB_DATABASE', env('DB_DATABASE', database_path('database.sqlite'))),
            'username' => env('CENTRAL_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('CENTRAL_DB_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('CENTRAL_DB_SOCKET', env('DB_SOCKET', '')),
            'charset' => env('CENTRAL_DB_CHARSET', env('DB_CHARSET', 'utf8mb4')),
            'collation' => env('CENTRAL_DB_COLLATION', env('DB_COLLATION', 'utf8mb4_unicode_ci')),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'foreign_key_constraints' => env('CENTRAL_DB_FOREIGN_KEYS', env('DB_FOREIGN_KEYS', true)),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('CENTRAL_MYSQL_ATTR_SSL_CA', env('MYSQL_ATTR_SSL_CA')),
            ]) + [
                // Report MATCHED rows from UPDATE, not CHANGED rows.
                //
                // Illuminate\Cache\DatabaseLock::refresh() renews a lock with
                // `->update(['expiration' => now + seconds]) >= 1`. Under MySQL's
                // default changed-row counting, renewing inside the same second
                // that the lock was taken writes an identical expiration, so zero
                // rows "change", refresh() returns false, and the holder concludes
                // it lost a lock it still owns — surfacing as PROVISIONING_LOCK_LOST
                // during shop provisioning.
                //
                // SQLite already counts matched rows, and the suite runs on SQLite,
                // so this aligns MySQL with the semantics the application is
                // written and tested against rather than introducing new ones.
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ] : [],
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) + [
                // Matched-row UPDATE counts — see the note on the central
                // connection. Required for Illuminate\Cache\DatabaseLock.
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ] : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) + [
                // Matched-row UPDATE counts — see the note on the central
                // connection. Required for Illuminate\Cache\DatabaseLock.
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ] : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
