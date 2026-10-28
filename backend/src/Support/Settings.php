<?php
declare(strict_types=1);

namespace Blissful\Support;

use Blissful\Db;

/** Platform settings stored as JSON, editable by superadmins in /admin. Cached per request. */
final class Settings
{
    private static ?array $cache = null;

    public static function get(string $name, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::get()->all('SELECT name, value_json FROM settings') as $r) {
                self::$cache[$r['name']] = json_decode($r['value_json'], true);
            }
        }
        return self::$cache[$name] ?? $default;
    }

    public static function int(string $name, int $default): int
    {
        $v = self::get($name, $default);
        return is_int($v) ? $v : $default;
    }

    public static function set(string $name, mixed $value, ?int $actorId): void
    {
        Db::get()->exec(
            'INSERT INTO settings (name, value_json, updated_by, updated_at) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$name, json_encode($value), $actorId, Clock::db()]
        );
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
