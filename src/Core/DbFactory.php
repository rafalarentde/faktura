<?php
declare(strict_types=1);

namespace App\Core;

/** Builds the Db from the `db` section of config/config.php. */
final class DbFactory
{
    /** @param array<string, mixed> $config the whole application config */
    public static function fromConfig(array $config): Db
    {
        $db = $config['db'] ?? [];
        $driver = (string) ($db['driver'] ?? '');
        return match ($driver) {
            'mysql' => new Db(
                new \PDO(self::mysqlDsn($db), (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), self::mysqlOptions()),
                'mysql'
            ),
            'sqlite' => new Db(
                new \PDO('sqlite:' . (string) ($db['sqlite'] ?? ''), null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]),
                'sqlite'
            ),
            default => throw new \InvalidArgumentException("Unsupported database driver: $driver"),
        };
    }

    /** @param array<string, mixed> $db */
    public static function mysqlDsn(array $db): string
    {
        $dsn = 'mysql:host=' . (string) ($db['host'] ?? 'localhost');
        if (!empty($db['port'])) {
            $dsn .= ';port=' . (int) $db['port'];
        }
        return $dsn . ';dbname=' . (string) ($db['name'] ?? '') . ';charset=utf8mb4';
    }

    /** @return array<int, mixed> */
    public static function mysqlOptions(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            // rowCount() reports matched rather than changed rows, like SQLite.
            \PDO::MYSQL_ATTR_FOUND_ROWS => true,
            // Strict mode: out-of-range or too-long values are errors, not silently truncated (money!);
            // UTC for the session so CURRENT_TIMESTAMP defaults match the UTC the application stores.
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'",
        ];
    }
}
