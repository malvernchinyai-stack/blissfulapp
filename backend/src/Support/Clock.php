<?php
declare(strict_types=1);

namespace Blissful\Support;

use DateTimeImmutable;
use DateTimeZone;

/** All time is UTC. Tests can shift "now" to exercise expiry and windows. */
final class Clock
{
    private static ?DateTimeImmutable $fixed = null;

    public static function now(): DateTimeImmutable
    {
        return self::$fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$fixed = $at?->setTimezone(new DateTimeZone('UTC'));
    }

    public static function db(?DateTimeImmutable $t = null): string
    {
        return ($t ?? self::now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function fromDb(?string $s): ?DateTimeImmutable
    {
        return $s === null ? null : new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    /** DB UTC string → ISO 8601 with Z for API responses. */
    public static function iso(?string $dbValue): ?string
    {
        return $dbValue === null ? null : self::fromDb($dbValue)->format('Y-m-d\TH:i:s\Z');
    }

    /** Parse an API timestamp (ISO 8601 with offset or Z). Rejects naive local times. */
    public static function parseIso(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public static function plusMinutes(DateTimeImmutable $t, int $minutes): DateTimeImmutable
    {
        return $t->modify(($minutes >= 0 ? '+' : '') . $minutes . ' minutes');
    }
}
