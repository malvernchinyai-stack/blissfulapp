<?php
declare(strict_types=1);

namespace Blissful\Support;

use Blissful\Config;

/**
 * Structured JSON-lines logging. Sensitive keys are redacted before writing, so
 * passwords, tokens, codes and addresses never reach log files or error reports.
 */
final class Log
{
    private const REDACT = ['password', 'new_password', 'token', 'access_token', 'refresh_token', 'code', 'authorization',
        'totp', 'secret', 'venue_address_private', 'phone', 'email', 'integration_key', 'hash', 'body'];

    public static function info(string $event, array $ctx = []): void
    {
        self::write('info', $event, $ctx);
    }

    public static function warning(string $event, array $ctx = []): void
    {
        self::write('warning', $event, $ctx);
    }

    public static function error(string $event, array $ctx = []): void
    {
        self::write('error', $event, $ctx);
    }

    public static function redact(array $ctx): array
    {
        foreach ($ctx as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT, true)) {
                $ctx[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $ctx[$k] = self::redact($v);
            }
        }
        return $ctx;
    }

    private static function write(string $level, string $event, array $ctx): void
    {
        $line = json_encode([
            'ts' => Clock::now()->format('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'env' => Config::env(),
            'event' => $event,
            'ctx' => self::redact($ctx),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $dir = Config::storagePath('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($dir . '/app-' . Clock::now()->format('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
