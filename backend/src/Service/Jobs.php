<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Support\Clock;
use Blissful\Support\Idempotency;
use Blissful\Support\Log;
use Blissful\Support\RateLimiter;
use Blissful\Support\Settings;

/** Scheduled work, run every minute by `php bin/cron.php` (DirectAdmin cron). Every job is safe to re-run. */
final class Jobs
{
    public static ?PushSender $push = null;

    public static function runAll(): array
    {
        if (!self::acquire('cron', 240)) {
            return ['skipped' => 'another run is in progress'];
        }
        $results = [];
        try {
            foreach (['expireRequests', 'startBookings', 'sendReminders', 'autoComplete', 'queuePayouts', 'deliverNotifications', 'pollPayments', 'housekeeping'] as $job) {
                try {
                    $results[$job] = self::$job();
                } catch (\Throwable $e) {
                    $results[$job] = 'error';
                    Log::error('job_failed', ['job' => $job, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            self::release('cron');
        }
        return $results;
    }

    public static function expireRequests(): int
    {
        $db = Db::get();
        $now = Clock::db();
        $rows = $db->all("SELECT id FROM requests WHERE status = 'open' AND (offer_deadline <= ? OR starts_at <= ?) LIMIT 500", [$now, $now]);
        foreach ($rows as $row) {
            $db->tx(function (Db $db) use ($row, $now) {
                $r = $db->one('SELECT * FROM requests WHERE id = ? FOR UPDATE', [$row['id']]);
                if ($r['status'] !== 'open') {
                    return;
                }
                $booked = (int) $db->value("SELECT COUNT(*) FROM bookings WHERE request_id = ? AND status <> 'cancelled'", [$r['id']]);
                $db->exec('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?', [$booked > 0 ? 'closed' : 'expired', $now, $r['id']]);
                $db->exec("UPDATE request_positions SET status = 'cancelled' WHERE request_id = ? AND status = 'open'", [$r['id']]);
                foreach ($db->all("SELECT * FROM offers WHERE request_id = ? AND status = 'submitted'", [$r['id']]) as $o) {
                    $db->exec("UPDATE offers SET status = 'expired', updated_at = ? WHERE id = ?", [$now, $o['id']]);
                    OfferService::revision($db, (int) $o['id'], (int) $r['client_id'], 'expired', (int) $o['price_minor'], (int) $o['extras_minor'], null, (int) $o['version']);
                    NotificationService::send((int) $o['musician_id'], 'offer_expired', 'Offer expired', "\"{$r['title']}\" closed without selecting your offer.",
                        ['request_id' => (int) $r['id']], "offer_expired:{$o['id']}");
                }
                if ($booked === 0) {
                    NotificationService::send((int) $r['client_id'], 'request_expired', 'Request expired', "\"{$r['title']}\" reached its deadline without a booking.",
                        ['request_id' => (int) $r['id']], "request_expired:{$r['id']}");
                }
            });
        }
        return count($rows);
    }

    public static function startBookings(): int
    {
        return Db::get()->exec("UPDATE bookings SET status = 'in_progress', version = version + 1, updated_at = ? WHERE status = 'confirmed' AND starts_at <= ?",
            [Clock::db(), Clock::db()]);
    }

    public static function sendReminders(): int
    {
        $db = Db::get();
        $rows = $db->all("SELECT b.*, r.title FROM bookings b JOIN requests r ON r.id = b.request_id
            WHERE b.status = 'confirmed' AND b.reminder_sent_at IS NULL AND b.starts_at > ? AND b.starts_at <= ? LIMIT 500",
            [Clock::db(), Clock::db(Clock::now()->modify('+24 hours'))]);
        foreach ($rows as $b) {
            $local = Clock::fromDb($b['starts_at'])->setTimezone(new \DateTimeZone($b['timezone']))->format('D j M, H:i');
            foreach ([(int) $b['client_id'], (int) $b['musician_id']] as $uid) {
                NotificationService::send($uid, 'reminder', 'Coming up: ' . $b['title'], "Starts {$local}.", ['booking_id' => (int) $b['id']],
                    "reminder:{$b['id']}:{$b['starts_at']}");
            }
            $db->exec('UPDATE bookings SET reminder_sent_at = ? WHERE id = ?', [Clock::db(), $b['id']]);
        }
        return count($rows);
    }

    /** If nobody confirms or disputes, a booking completes automatically some hours after it ends. */
    public static function autoComplete(): int
    {
        $db = Db::get();
        $hours = Settings::int('auto_complete_hours', 48);
        $rows = $db->all("SELECT id FROM bookings WHERE status IN ('confirmed','in_progress') AND ends_at <= ? LIMIT 500",
            [Clock::db(Clock::now()->modify("-{$hours} hours"))]);
        foreach ($rows as $row) {
            $db->tx(function (Db $db) use ($row) {
                $b = $db->one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$row['id']]);
                if (in_array($b['status'], ['confirmed', 'in_progress'], true)) {
                    BookingService::complete($db, $b, null);
                }
            });
        }
        return count($rows);
    }

    public static function queuePayouts(): int
    {
        return PaymentService::queueDuePayouts();
    }

    /** Push outbox with exponential backoff. Notifications are created once (dedupe key); delivery may retry. */
    public static function deliverNotifications(): int
    {
        $db = Db::get();
        $push = self::$push ?? new PushSender();
        $rows = $db->all("SELECT o.*, n.user_id, n.title, n.body, n.data_json, n.type FROM notification_outbox o JOIN notifications n ON n.id = o.notification_id
                          WHERE o.status = 'pending' AND o.next_attempt_at <= ? ORDER BY o.id LIMIT 200", [Clock::db()]);
        if ($rows !== [] && !$push->configured()) {
            $db->exec("UPDATE notification_outbox SET status = 'skipped', last_error = 'push_not_configured', updated_at = ? WHERE status = 'pending' AND next_attempt_at <= ?",
                [Clock::db(), Clock::db()]);
            return 0;
        }
        $sent = 0;
        foreach ($rows as $o) {
            // claim the row so a concurrent run cannot send it twice
            $claimed = $db->exec("UPDATE notification_outbox SET attempts = attempts + 1, next_attempt_at = ?, updated_at = ? WHERE id = ? AND status = 'pending' AND attempts = ?",
                [Clock::db(Clock::now()->modify('+10 minutes')), Clock::db(), $o['id'], $o['attempts']]);
            if ($claimed === 0) {
                continue;
            }
            $tokens = $db->all('SELECT id, token FROM device_tokens WHERE user_id = ?', [$o['user_id']]);
            $ok = false;
            $retry = false;
            $data = (json_decode((string) $o['data_json'], true) ?: []) + ['type' => $o['type'], 'notification_id' => (string) $o['notification_id']];
            foreach ($tokens as $t) {
                $res = $push->send($t['token'], $o['title'], $o['body'], $data);
                if ($res === 'sent') {
                    $ok = true;
                } elseif ($res === 'invalid_token') {
                    $db->exec('DELETE FROM device_tokens WHERE id = ?', [$t['id']]);
                } else {
                    $retry = true;
                }
            }
            $attempts = (int) $o['attempts'] + 1;
            if ($ok || $tokens === []) {
                $db->exec('UPDATE notification_outbox SET status = ?, last_error = ?, updated_at = ? WHERE id = ?',
                    [$ok ? 'sent' : 'skipped', $ok ? null : 'no_device', Clock::db(), $o['id']]);
                $sent += $ok ? 1 : 0;
            } elseif ($retry && $attempts < 6) {
                $db->exec('UPDATE notification_outbox SET next_attempt_at = ?, last_error = ?, updated_at = ? WHERE id = ?',
                    [Clock::db(Clock::now()->modify('+' . (2 ** $attempts) . ' minutes')), 'retry', Clock::db(), $o['id']]);
            } else {
                $db->exec("UPDATE notification_outbox SET status = 'failed', last_error = 'gave_up', updated_at = ? WHERE id = ?", [Clock::db(), $o['id']]);
            }
        }
        return $sent;
    }

    public static function pollPayments(): int
    {
        return (new PaymentService())->pollPending();
    }

    public static function housekeeping(): array
    {
        $db = Db::get();
        return [
            'rate_limits' => RateLimiter::purge(),
            'idempotency' => Idempotency::purge(),
            'tokens' => $db->exec('DELETE FROM auth_tokens WHERE expires_at < ?', [Clock::db(Clock::now()->modify('-7 days'))]),
            'codes' => $db->exec('DELETE FROM verification_codes WHERE expires_at < ?', [Clock::db(Clock::now()->modify('-1 day'))]),
            'admin_sessions' => $db->exec('DELETE FROM admin_sessions WHERE expires_at < ?', [Clock::db()]),
            'files' => (new FileService())->purgeDeleted(),
        ];
    }

    private static function acquire(string $name, int $seconds): bool
    {
        $db = Db::get();
        $db->exec('INSERT IGNORE INTO job_locks (name, locked_until) VALUES (?, ?)', [$name, Clock::db(Clock::now()->modify('-1 second'))]);
        return $db->exec('UPDATE job_locks SET locked_until = ? WHERE name = ? AND locked_until < ?',
            [Clock::db(Clock::now()->modify("+{$seconds} seconds")), $name, Clock::db()]) === 1;
    }

    private static function release(string $name): void
    {
        Db::get()->exec('UPDATE job_locks SET locked_until = ? WHERE name = ?', [Clock::db(Clock::now()->modify('-1 second')), $name]);
    }
}
