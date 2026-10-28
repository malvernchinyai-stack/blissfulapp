<?php
declare(strict_types=1);

namespace Blissful\Support;

use Blissful\Db;
use Blissful\Http\ApiError;

/** Fixed-window counter in MySQL (works on shared hosting without Redis). */
final class RateLimiter
{
    public static function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        if (\Blissful\Config::bool('RATE_LIMIT_DISABLED')) {
            return true;
        }
        $now = Clock::now()->getTimestamp();
        $window = intdiv($now, $windowSeconds) * $windowSeconds;
        $key = substr(hash('sha256', $bucket), 0, 40) . ':' . $windowSeconds;
        $db = Db::get();
        $db->exec(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$key, $window]
        );
        $hits = (int) $db->value('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$key, $window]);
        return $hits <= $limit;
    }

    public static function enforce(string $bucket, int $limit, int $windowSeconds, string $message = 'Too many attempts. Please wait and try again.'): void
    {
        if (!self::hit($bucket, $limit, $windowSeconds)) {
            throw new ApiError(429, 'rate_limited', $message);
        }
    }

    public static function purge(): int
    {
        return Db::get()->exec('DELETE FROM rate_limits WHERE window_start < ?', [Clock::now()->getTimestamp() - 86400]);
    }
}
