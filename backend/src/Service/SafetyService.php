<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Support\Audit;
use Blissful\Support\Clock;
use Blissful\Support\RateLimiter;
use Blissful\Support\Validator;

final class SafetyService
{
    public function block(int $userId, int $targetId): array
    {
        if ($userId === $targetId) {
            throw ApiError::validation(['user_id' => 'You cannot block yourself.']);
        }
        if (!Db::get()->value('SELECT 1 FROM users WHERE id = ?', [$targetId])) {
            throw ApiError::notFound('User');
        }
        Db::get()->exec('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id, created_at) VALUES (?,?,?)', [$userId, $targetId, Clock::db()]);
        return $this->blocks($userId);
    }

    public function unblock(int $userId, int $targetId): array
    {
        Db::get()->exec('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [$userId, $targetId]);
        return $this->blocks($userId);
    }

    public function blocks(int $userId): array
    {
        return array_map(fn ($r) => ['user_id' => (int) $r['blocked_id'], 'name' => $r['display_name'], 'since' => Clock::iso($r['created_at'])],
            Db::get()->all('SELECT b.blocked_id, b.created_at, u.display_name FROM user_blocks b JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = ? ORDER BY b.created_at DESC', [$userId]));
    }

    public function report(int $userId, array $input): array
    {
        $v = new Validator($input);
        $type = $v->enum('target_type', ['user', 'request', 'offer', 'message', 'review']);
        $targetId = $v->int('target_id', 1, PHP_INT_MAX);
        $reason = $v->enum('reason', ['spam', 'abuse', 'fraud', 'inappropriate', 'safety', 'other']);
        $details = $v->string('details', 0, 2000, false);
        $v->done();
        RateLimiter::enforce("report:{$userId}", 20, 86400, 'You have sent many reports today. Our team will review them.');
        if (!$this->canSee($userId, $type, $targetId)) {
            throw ApiError::notFound('Item');
        }
        $id = Db::get()->insert('reports', ['reporter_id' => $userId, 'target_type' => $type, 'target_id' => $targetId, 'reason' => $reason,
            'details' => $details, 'created_at' => Clock::db()]);
        return ['id' => $id, 'status' => 'open'];
    }

    public function openDispute(int $userId, int $bookingId, array $input): array
    {
        $v = new Validator($input);
        $reason = $v->enum('reason', ['no_show', 'late', 'quality', 'payment', 'conduct', 'other']);
        $details = $v->string('details', 10, 3000);
        $v->done();
        Db::get()->tx(function (Db $db) use ($userId, $bookingId, $reason, $details) {
            $b = $db->one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            if ($b === null || ((int) $b['client_id'] !== $userId && (int) $b['musician_id'] !== $userId)) {
                throw ApiError::notFound('Booking');
            }
            if (!(new BookingService())->canDispute($b)) {
                throw ApiError::conflict('dispute_not_allowed', 'A problem can be reported from the event start until the dispute window closes.');
            }
            if ($db->value('SELECT 1 FROM disputes WHERE booking_id = ?', [$bookingId])) {
                throw ApiError::conflict('dispute_exists', 'A dispute is already open for this booking.');
            }
            $now = Clock::db();
            $db->insert('disputes', ['booking_id' => $bookingId, 'opened_by' => $userId, 'reason' => $reason, 'details' => $details,
                'previous_status' => $b['status'], 'created_at' => $now]);
            $db->exec("UPDATE bookings SET status = 'disputed', version = version + 1, updated_at = ? WHERE id = ?", [$now, $bookingId]);
            $db->insert('booking_events', ['booking_id' => $bookingId, 'actor_id' => $userId, 'event' => 'disputed', 'created_at' => $now]);
            $other = (int) $b['client_id'] === $userId ? (int) $b['musician_id'] : (int) $b['client_id'];
            NotificationService::send($other, 'dispute_opened', 'A problem was reported', 'Blissful Studios support will review this booking and contact you.',
                ['booking_id' => $bookingId], "dispute:{$bookingId}");
        });
        return (new BookingService())->detail($bookingId, $userId);
    }

    /**
     * Staff resolution. outcome=complete → booking completed (payout can proceed);
     * outcome=cancel → booking cancelled with the given refund recorded against any payment.
     */
    public static function resolveDispute(int $staffId, int $disputeId, string $outcome, string $resolution, int $refundMinor): void
    {
        Db::get()->tx(function (Db $db) use ($staffId, $disputeId, $outcome, $resolution, $refundMinor) {
            $d = $db->one('SELECT * FROM disputes WHERE id = ? FOR UPDATE', [$disputeId]);
            if ($d === null || $d['status'] === 'resolved') {
                throw ApiError::conflict('dispute_closed', 'Dispute already resolved.');
            }
            $b = $db->one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$d['booking_id']]);
            $now = Clock::now();
            if ($outcome === 'complete') {
                if ($b['completed_at'] === null) {
                    $db->exec('UPDATE musician_profiles SET completed_count = completed_count + 1 WHERE user_id = ?', [$b['musician_id']]);
                }
                $db->exec("UPDATE bookings SET status = 'completed', completed_at = COALESCE(completed_at, ?), dispute_window_ends_at = ?, version = version + 1, updated_at = ? WHERE id = ?",
                    [Clock::db($now), Clock::db($now), Clock::db($now), $b['id']]);
            } else {
                if ($refundMinor < 0 || $refundMinor > (int) $b['total_minor']) {
                    throw ApiError::validation(['refund_minor' => 'Refund must be between 0 and the booking total.']);
                }
                $db->exec("UPDATE bookings SET status = 'cancelled', cancel_reason = ?, cancellation_charge_minor = ?, version = version + 1, updated_at = ? WHERE id = ?",
                    ['Dispute resolution: ' . mb_substr($resolution, 0, 400), (int) $b['total_minor'] - $refundMinor, Clock::db($now), $b['id']]);
                (new PaymentService())->handleCancellation($db, $b, ['refund_if_paid_minor' => $refundMinor, 'charge_minor' => (int) $b['total_minor'] - $refundMinor], $staffId);
            }
            $db->exec("UPDATE disputes SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = ? WHERE id = ?", [$resolution, $staffId, Clock::db($now), $disputeId]);
            $db->insert('booking_events', ['booking_id' => $b['id'], 'actor_id' => $staffId, 'event' => 'dispute_resolved',
                'detail_json' => json_encode(['outcome' => $outcome, 'refund_minor' => $refundMinor]), 'created_at' => Clock::db($now)]);
            foreach ([(int) $b['client_id'], (int) $b['musician_id']] as $uid) {
                NotificationService::send($uid, 'dispute_resolved', 'Dispute resolved', mb_substr($resolution, 0, 200), ['booking_id' => (int) $b['id']], "dispute_resolved:{$disputeId}");
            }
            Audit::record($staffId, 'dispute.resolved', 'dispute', $disputeId, ['outcome' => $outcome, 'refund_minor' => $refundMinor]);
        });
    }

    private function canSee(int $userId, string $type, int $id): bool
    {
        $db = Db::get();
        return match ($type) {
            'user' => (bool) $db->value("SELECT 1 FROM users WHERE id = ? AND id <> ?", [$id, $userId]),
            'request' => (bool) $db->value("SELECT 1 FROM requests WHERE id = ? AND (status = 'open' OR client_id = ? OR EXISTS (SELECT 1 FROM offers o WHERE o.request_id = requests.id AND o.musician_id = ?))", [$id, $userId, $userId]),
            'offer' => (bool) $db->value('SELECT 1 FROM offers o JOIN requests r ON r.id = o.request_id WHERE o.id = ? AND (o.musician_id = ? OR r.client_id = ?)', [$id, $userId, $userId]),
            'message' => (bool) $db->value('SELECT 1 FROM messages m JOIN bookings b ON b.id = m.booking_id WHERE m.id = ? AND (b.client_id = ? OR b.musician_id = ?)', [$id, $userId, $userId]),
            'review' => (bool) $db->value('SELECT 1 FROM reviews WHERE id = ? AND is_hidden = 0', [$id]),
            default => false,
        };
    }
}
