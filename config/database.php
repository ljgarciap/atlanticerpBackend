<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

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

    'default' => env('DB_CONNECTION', 'sqlite'),

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
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
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
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
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
            // SCRUM-883 — sin esto, las columnas timestamptz (stage_changed_at, etc.) se
            // interpretan con el timezone de SESIÓN de Postgres (default del servidor, nunca
            // tocado), independiente del default de PHP de arriba (config('app.timezone')). Un
            // valor Carbon en hora de Panamá insertado en una columna timestamptz sin esto se
            // reinterpreta como si fuera UTC al guardar — corrompe el instante real, no solo la
            // presentación (5h de corrimiento). Encontrado corriendo el gate completo tras el fix
            // de config('app.timezone'): 18 tests reales rotos en AdminCont/Comisiones Internas,
            // SecurityAnalysisJob y tiempos de resolución de Servicios — todos ligados a columnas
            // timestamptz comparadas contra `now()`.
            'timezone' => env('APP_TIMEZONE', 'America/Panama'),
        ],

        'landlord' => [
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
            // SCRUM-883 — mismo motivo que la conexión 'pgsql' de arriba, por consistencia.
            'timezone' => env('APP_TIMEZONE', 'America/Panama'),
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

        // SCRUM-750 (épica SCRUM-749 — Migración ICG→AtlanticERP) — conexión dedicada al SQL Server de
        // ICG Software, separada del stub genérico `sqlsrv` de arriba (que sigue libre para otro
        // uso) para no pisarlo con env vars propias. Apunta al gateway VPN dedicado en AWS
        // (`atlanticerp-legacy-gw`, IP privada 172.31.26.125:1433 — ver
        // Illumination/migracion-icg-diseno.md §8.1), nunca directo al SQL Server del cliente.
        // Usuario `illumiread`, solo lectura. Solo lo usa App\Modules\Legacy\Extractors\*.
        'legacy_icg' => [
            'driver' => 'sqlsrv',
            'host' => env('LEGACY_ICG_DB_HOST'),
            'port' => env('LEGACY_ICG_DB_PORT', '1433'),
            'database' => env('LEGACY_ICG_DB_DATABASE', 'ILLUMINATION'),
            'username' => env('LEGACY_ICG_DB_USERNAME'),
            'password' => env('LEGACY_ICG_DB_PASSWORD'),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // Descubierto 2026-08-12 (SCRUM-750, Tarea 6, primera corrida real contra ICG): el SQL
            // Server 2016 on-prem del cliente usa un certificado autofirmado — ODBC Driver 18 (a
            // diferencia del 17) valida el certificado por defecto y rechaza la conexión
            // (SQLSTATE[08001] "self-signed certificate"). Confiar en el certificado es aceptable
            // acá porque el tráfico nunca sale a Internet — viaja por el túnel OpenVPN dedicado del
            // gateway (Illumination/migracion-icg-diseno.md §8.1), no por una red pública.
            'encrypt' => env('LEGACY_ICG_DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('LEGACY_ICG_DB_TRUST_SERVER_CERTIFICATE', 'true'),
        ],

        // SCRUM-970 (épica SCRUM-969 — integración Lafayette Lighting, Bodega Zona Libre) —
        // conexión de solo lectura a la MariaDB externa del proveedor
        // (app_lafayette.vista_productos_lighting). A diferencia de `legacy_icg` (túnel VPN
        // privado, nunca sale a Internet), esta conexión SÍ viaja por Internet público — no se
        // puede usar `trust_server_certificate`, hace falta validar identidad real.
        //
        // El proveedor mencionó un `ca.pem` en el correo original pero nunca lo adjuntó, y
        // Lafayette confirmó informalmente que "no haría falta" — resultó ser incorrecto: mysqlnd
        // (a diferencia del cliente CLI mysql/mariadb) SÍ exige una CA real con `CA:TRUE` para
        // verificar la cadena — un certificado de hoja pinneado como CA (lo que se probó primero)
        // falla en mysqlnd con "Cannot connect to MySQL using SSL" aunque conecte bien por CLI.
        // Solución real: el servidor SÍ envía la cadena completa en el handshake TLS
        // (`openssl s_client -starttls mysql -showcerts`, 2 certificados) — el segundo es la CA
        // raíz real (`Grupo Lafayette MariaDB Root CA`, CA:TRUE, autofirmada). Su huella SHA256
        // (`4B:6C:9E:B1:32:54:26:69:7F:90:41:99:67:BF:1C:5F:F4:5A:EB:AD:3D:BF:AE:A6:84:73:A9:A1:A4:84:85:D5`)
        // coincide EXACTO con la que Lafayette compartió en el correo original — confirma que es
        // la CA legítima, no solo "confío en lo que veo la primera vez".
        // `storage/certs/lafayette-ca.pem` (versionado en git — es un certificado, información
        // pública, no un secreto) es esa CA real. Vigente hasta 2036-09-22 — si Lafayette la rota,
        // re-extraerla con el mismo comando (documentado en SCRUM-969/970) y reemplazar el archivo.
        'lafayette_lighting' => [
            'driver' => 'mysql',
            'host' => env('LAFAYETTE_DB_HOST', 'app.grupolafayette.com'),
            'port' => env('LAFAYETTE_DB_PORT', '3306'),
            'database' => env('LAFAYETTE_DB_DATABASE', 'app_lafayette'),
            'username' => env('LAFAYETTE_DB_USERNAME'),
            'password' => env('LAFAYETTE_DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'prefix' => '',
            'prefix_indexes' => true,
            'options' => extension_loaded('pdo_mysql') ? [
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => storage_path('certs/lafayette-ca.pem'),
                // Pdo\Mysql (PHP 8.5+) no expone ATTR_SSL_VERIFY_SERVER_CERT como constante propia
                // todavía — a diferencia de ATTR_SSL_CA, esta sigue siendo solo la clásica de PDO.
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ] : [],
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
            // SCRUM-1031: timeout de CONEXIÓN (no de lectura: las colas usan BLPOP). Si Redis no responde, falla rápido y se aplica la política
            // fail-open/fail-closed en vez de esperar el timeout por defecto del socket.
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT', 2),
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
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT', 2),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
