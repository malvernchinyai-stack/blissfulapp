<?php
declare(strict_types=1);

namespace Blissful;

/**
 * Reads configuration from real environment variables first, then from a `.env` file that lives
 * OUTSIDE the web root (shared hosting cannot always set env vars). Never commit `.env`.
 */
final class Config
{
    private static array $values = [];
    private static string $root = '';

    public static function load(string $root): void
    {
        self::$root = $root;
        $file = getenv('BLISSFUL_ENV_FILE') ?: $root . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $v = trim($v);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                self::$values[trim($k)] = $v;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            throw new \RuntimeException("Missing required configuration: {$key}");
        }
        return $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, ?string $value): void
    {
        if ($value === null) {
            unset(self::$values[$key]);
        } else {
            self::$values[$key] = $value;
        }
    }

    public static function env(): string
    {
        return self::get('APP_ENV', 'development');
    }

    public static function root(): string
    {
        return self::$root;
    }

    public static function storagePath(string $sub = ''): string
    {
        $base = self::get('STORAGE_PATH', self::$root . '/storage');
        return rtrim($base, '/') . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }
}
