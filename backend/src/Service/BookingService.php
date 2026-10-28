<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Support\Clock;
use Blissful\Support\Money;
use Blissful\Support\Settings;
use Blissful\Support\Validator;
use DateTimeImmutable;

final class BookingService
{
    public const ACTIVE = ['confirmed', 'in_progress', 'disputed'];

    /**
     * True if the musician has an active booking overlapping [start − setup, start + duration).
     * Setup time of both bookings counts as busy time. All comparisons in UTC.
     */
    public static function hasConflict(Db $db, int $musicianId, DateTimeImmutable $start, int $durationMin, int $setupMin, ?int $excludeBookingId): bool
    {
        $busyFrom = Clock::plusMinutes($start, -$setupMin);
        $busyTo = Clock::plusMinutes($start, $durationMin);
        $sql = "SELECT 1 FROM bookings WHERE musician_id = ? AND status IN ('confirmed','in_progress')
                AND DATE_SUB(starts_at, INTERVAL setup_minutes MINUTE) < ? AND ends_at > ?";
        $params = [$musicianId, Clock::db($busyTo), Clock::db($busyFrom)];
        if ($excludeBookingId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeBookingId;
        }
        return $db->value($sql . ' LIMIT 1', $params) !== null;
    }

    public function list(int $userId, array $q): array
    {
        $role = ($q['role'] ?? 'client') === 'musician' ? 'musician' : 'client';
        $col = $role === 'musician' ? 'b.musician_id' : 'b.client_id';
        $scope = $q['scope'] ?? 'upcoming';
        $where = "{$col} = ?";
        $params = [$userId];
        if ($scope === 'past') {
            $where .= " AND (b.status IN ('completed','cancelled') OR b.ends_at < ?)";
            $params[] = Clock::db();
            $order = 'b.starts_at DESC';
        } else {
            $where .= " AND b.status IN ('confirmed','in_progress','disputed') AND (b.ends_at >= ? OR b.status IN ('in_progress','disputed'))";
            $params[] = Clock::db(Clock::now()->modify('-3 days'));
            $order = 'b.starts_at ASC';
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $rows = Db::get()->all("SELECT b.*, r.title, r.city, i.name AS instrument, mp.stage_name, cp.name AS client_name
            FROM bookings b JOIN requests r ON r.id = b.request_id JOIN request_positions p ON p.id = b.position_id
            JOIN instruments i ON i.id = p.instrument_id JOIN musician_profiles mp ON mp.user_id = b.musician_id
            JOIN client_profiles cp ON cp.user_id = b.client_id
            WHERE {$where} ORDER BY {$order}, b.id ASC LIMIT 21 OFFSET " . (($page - 1) * 20), $params);
        return [
            'items' => array_map(fn ($b) => [
                'id' => (int) $b['id'], 'title' => $b['title'], 'city' => $b['city'], 'instrument' => $b['instrument'],
                'status' => $b['status'], 'starts_at' => Clock::iso($b['starts_at']), 'ends_at' => Clock::iso($b['ends_at']),
                'timezone' => $b['timezone'], 'total_minor' => (int) $b['total_minor'], 'currency' => $b['currency'],
                'counterpart_name' => $role === 'client' ? $b['stage_name'] : $b['client_name'],
                'payment_status' => $this->paymentStatus((int) $b['id']),
            ], array_slice($rows, 0, 20)),
            'page' => $page, 'has_more' => count($rows) > 20,
        ];
    }

    public function detail(int $bookingId, int $viewerId): array
    {
        $db = Db::get();
        $b = $this->participantBooking($bookingId, $viewerId);
        $r = $db->one('SELECT * FROM requests WHERE id = ?', [$b['request_id']]);
        $isClient = (int) $b['client_id'] === $viewerId;
        $musician = $db->one('SELECT m.stage_name, m.photo_file_id, u.phone FROM musician_profiles m JOIN users u ON u.id = m.user_id WHERE m.user_id = ?', [$b['musician_id']]);
        $client = $db->one('SELECT c.name, c.org_type, c.photo_file_id, u.phone FROM client_profiles c JOIN users u ON u.id = c.user_id WHERE c.user_id = ?', [$b['client_id']]);
        $active = in_array($b['status'], self::ACTIVE, true);
        $terms = json_decode($b['terms_json'], true);
        $pending = $db->one("SELECT * FROM reschedule_proposals WHERE booking_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$bookingId]);
        $dispute = $db->one('SELECT * FROM disputes WHERE booking_id = ?', [$bookingId]);
        $myReview = $db->value('SELECT 1 FROM reviews WHERE booking_id = ? AND author_id = ?', [$bookingId, $viewerId]);
        $now = Clock::now();
        $start = Clock::fromDb($b['starts_at']);
        return [
            'id' => (int) $b['id'],
            'request_id' => (int) $b['request_id'],
            'offer_id' => (int) $b['offer_id'],
            'title' => $r['title'],
            'event_type' => $r['event_type'],
            'status' => $b['status'],
            'my_role' => $isClient ? 'client' : 'musician',
            'starts_at' => Clock::iso($b['starts_at']),
            'ends_at' => Clock::iso($b['ends_at']),
            'setup_minutes' => (int) $b['setup_minutes'],
            'timezone' => $b['timezone'],
            'city' => $r['city'],
            'area' => $r['area'],
            'venue_name' => $r['venue_name'],
            'venue_address' => $r['venue_address_private'],
            'equipment_available' => $r['equipment_available'],
            'travel_notes' => $r['travel_notes'],
            'rehearsal_notes' => $r['rehearsal_notes'],
            'price' => Money::breakdown((int) $b['price_minor'], (int) $b['extras_minor'], (int) $b['fee_bps']),
            'currency' => $b['currency'],
            'terms' => $terms,
            'musician' => ['user_id' => (int) $b['musician_id'], 'stage_name' => $musician['stage_name'],
                'photo_url' => FileService::url($musician['photo_file_id'] !== null ? (int) $musician['photo_file_id'] : null),
                'phone' => $active ? $musician['phone'] : null],
            'client' => ['user_id' => (int) $b['client_id'], 'name' => $client['name'], 'org_type' => $client['org_type'],
                'photo_url' => FileService::url($client['photo_file_id'] !== null ? (int) $client['photo_file_id'] : null),
                'phone' => $active ? $client['phone'] : null],
            'client_marked_done' => $b['client_done_at'] !== null,
            'musician_marked_done' => $b['musician_done_at'] !== null,
            'completed_at' => Clock::iso($b['completed_at']),
            'dispute_window_ends_at' => Clock::iso($b['dispute_window_ends_at']),
            'cancel_reason' => $b['cancel_reason'],
            'cancelled_by_me' => $b['cancelled_by'] !== null ? (int) $b['cancelled_by'] === $viewerId : null,
            'cancellation_charge_minor' => $b['cancellation_charge_minor'] !== null ? (int) $b['cancellation_charge_minor'] : null,
            'cancellation_preview' => $b['status'] === 'confirmed' ? $this->cancellationQuote($b, $isClient) : null,
            'pending_reschedule' => $pending ? [
                'id' => (int) $pending['id'], 'proposed_by_me' => (int) $pending['proposed_by'] === $viewerId,
                'new_starts_at' => Clock::iso($pending['new_starts_at']), 'new_duration_minutes' => (int) $pending['new_duration_minutes'],
                'note' => $pending['note'],
            ] : null,
            'dispute' => $dispute ? ['id' => (int) $dispute['id'], 'status' => $dispute['status'], 'reason' => $dispute['reason'],
                'opened_by_me' => (int) $dispute['opened_by'] === $viewerId, 'resolution' => $dispute['resolution']] : null,
            'payment_status' => $this->paymentStatus($bookingId),
            'can' => [
                'cancel' => $b['status'] === 'confirmed',
                'reschedule' => $b['status'] === 'confirmed' && $start > $now,
                'mark_done' => in_array($b['status'], ['confirmed', 'in_progress'], true) && $start <= $now
                    && ($isClient ? $b['client_done_at'] === null : $b['musician_done_at'] === null),
                'dispute' => $this->canDispute($b) && $dispute === null,
                'review' => $b['status'] === 'completed' && !$myReview,
                'pay' => $isClient && in_array($b['status'], ['confirmed', 'in_progress'], true) && !in_array($this->paymentStatus($bookingId), ['paid', 'pending'], true),
                'chat' => $this->chatOpen($b),
            ],
            'version' => (int) $b['version'],
        ];
    }

    public function markDone(int $userId, int $bookingId): array
    {
        Db::get()->tx(function (Db $db) use ($userId, $bookingId) {
            $b = $this->lockParticipant($db, $bookingId, $userId);
            if (!in_array($b['status'], ['confirmed', 'in_progress'], true)) {
                throw ApiError::conflict('bad_state', 'This booking cannot be marked as done.');
            }
            if (Clock::fromDb($b['starts_at']) > Clock::now()) {
                throw ApiError::conflict('not_started', 'You can mark the booking as done after it has started.');
            }
            $col = (int) $b['client_id'] === $userId ? 'client_done_at' : 'musician_done_at';
            $now = Clock::db();
            $db->exec("UPDATE bookings SET {$col} = COALESCE({$col}, ?), status = 'in_progress', version = version + 1, updated_at = ? WHERE id = ?", [$now, $now, $bookingId]);
            $db->insert('booking_events', ['booking_id' => $bookingId, 'actor_id' => $userId, 'event' => 'marked_done', 'created_at' => $now]);
            $b = $db->one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
            if ($b['client_done_at'] !== null && $b['musician_done_at'] !== null) {
                self::complete($db, $b, $userId);
            } else {
                $other = (int) $b['client_id'] === $userId ? (int) $b['musician_id'] : (int) $b['client_id'];
                NotificationService::send($other, 'booking_completed', 'Please confirm the booking is complete',
                    'The other party marked the booking as done.', ['booking_id' => $bookingId], "done_ask:{$bookingId}:{$userId}");
            }
        });
        return $this->detail($bookingId, $userId);
    }

    public static function complete(Db $db, array $b, ?int $actorId): void
    {
        $now = Clock::now();
        $window = Settings::int('dispute_window_hours', 48);
        $db->exec("UPDATE bookings SET status = 'completed', completed_at = ?, dispute_window_ends_at = ?, version = version + 1, updated_at = ? WHERE id = ? AND status IN ('confirmed','in_progress')",
            [Clock::db($now), Clock::db($now->modify("+{$window} hours")), Clock::db($now), $b['id']]);
        $db->exec('UPDATE musician_profiles SET completed_count = completed_count + 1 WHERE user_id = ?', [$b['musician_id']]);
        $db->insert('booking_events', ['booking_id' => $b['id'], 'actor_id' => $actorId, 'event' => 'completed', 'created_at' => Clock::db($now)]);
        foreach ([(int) $b['client_id'], (int) $b['musician_id']] as $uid) {
            NotificationService::send($uid, 'booking_completed', 'Booking completed', "Leave a review. You have {$window} hours to report a problem.",
                ['booking_id' => (int) $b['id']], "completed:{$b['id']}");
        }
    }

    public function cancel(int $userId, int $bookingId, array $input): array
    {
        $v = new Validator($input);
        $reason = $v->string('reason', 5, 500);
        $v->done();
        Db::get()->tx(function (Db $db) use ($userId, $bookingId, $reason) {
            $b = $this->lockParticipant($db, $bookingId, $userId);
            if ($b['status'] !== 'confirmed') {
                throw ApiError::conflict('bad_state', 'Only confirmed bookings that have not started can be cancelled. Open a dispute instead.');
            }
            $isClient = (int) $b['client_id'] === $userId;
            $quote = $this->cancellationQuote($b, $isClient);
            $now = Clock::db();
            $db->exec("UPDATE bookings SET status = 'cancelled', cancelled_by = ?, cancel_reason = ?, cancellation_charge_minor = ?, version = version + 1, updated_at = ? WHERE id = ?",
                [$userId, $reason, $quote['charge_minor'], $now, $bookingId]);
            $db->insert('booking_events', ['booking_id' => $bookingId, 'actor_id' => $userId, 'event' => 'cancelled',
                'detail_json' => json_encode(['by' => $isClient ? 'client' : 'musician'] + $quote), 'created_at' => $now]);
            $db->exec("UPDATE reschedule_proposals SET status = 'withdrawn' WHERE booking_id = ? AND status = 'pending'", [$bookingId]);
            // Free the place again so the client can hire someone else, if the event is still ahead and deadline open.
            $pos = $db->one('SELECT * FROM request_positions WHERE id = ? FOR UPDATE', [$b['position_id']]);
            $db->exec("UPDATE request_positions SET filled_count = GREATEST(filled_count - 1, 0), status = IF(status = 'cancelled', 'cancelled', 'open') WHERE id = ?", [$pos['id']]);
            $req = $db->one('SELECT * FROM requests WHERE id = ? FOR UPDATE', [$b['request_id']]);
            if ($req['status'] === 'filled' && Clock::fromDb($req['offer_deadline']) > Clock::now() && Clock::fromDb($req['starts_at']) > Clock::now()) {
                $db->exec("UPDATE requests SET status = 'open', updated_at = ? WHERE id = ?", [$now, $req['id']]);
            } elseif ($req['status'] === 'filled') {
                $db->exec("UPDATE requests SET status = 'closed', updated_at = ? WHERE id = ?", [$now, $req['id']]);
            }
            (new PaymentService())->handleCancellation($db, $b, $quote);
            $other = $isClient ? (int) $b['musician_id'] : (int) $b['client_id'];
            NotificationService::send($other, 'booking_cancelled', 'Booking cancelled', "\"{$req['title']}\" was cancelled. Reason: {$reason}",
                ['booking_id' => $bookingId], "cancelled:{$bookingId}");
        });
        return $this->detail($bookingId, $userId);
    }

    /** Charge per the policy stored on the booking at acceptance time (later policy edits don't apply retroactively). */
    public function cancellationQuote(array $b, bool $byClient): array
    {
        $terms = json_decode($b['terms_json'], true);
        $policy = $terms['cancellation_policy'] ?? Settings::get('cancellation_policy');
        $hoursBefore = intdiv(Clock::fromDb($b['starts_at'])->getTimestamp() - Clock::now()->getTimestamp(), 3600);
        if ($byClient) {
            $bps = 10000;
            $tiers = $policy['tiers'] ?? [];
            usort($tiers, fn ($a, $c) => $c['min_hours_before'] <=> $a['min_hours_before']);
            foreach ($tiers as $t) {
                if ($hoursBefore >= (int) $t['min_hours_before']) {
                    $bps = (int) $t['charge_bps'];
                    break;
                }
            }
        } else {
            $bps = (int) ($policy['musician_cancel_charge_bps'] ?? 0);
        }
        $charge = Money::applyBps((int) $b['total_minor'], $bps);
        return ['hours_before_start' => max(0, $hoursBefore), 'charge_bps' => $bps, 'charge_minor' => $charge,
            'refund_if_paid_minor' => (int) $b['total_minor'] - $charge, 'currency' => $b['currency']];
    }

    public function proposeReschedule(int $userId, int $bookingId, array $input): array
    {
        $v = new Validator($input);
        $start = $v->datetime('new_starts_at');
        $duration = $v->int('new_duration_minutes', 15, 1440, false);
        $note = $v->string('note', 0, 500, false);
        $v->done();
        if ($start <= Clock::now()->modify('+1 hour')) {
            throw ApiError::validation(['new_starts_at' => 'The new time must be at least an hour from now.']);
        }
        Db::get()->tx(function (Db $db) use ($userId, $bookingId, $start, $duration, $note) {
            $b = $this->lockParticipant($db, $bookingId, $userId);
            if ($b['status'] !== 'confirmed') {
                throw ApiError::conflict('bad_state', 'Only confirmed bookings can be rescheduled.');
            }
            $duration ??= intdiv(Clock::fromDb($b['ends_at'])->getTimestamp() - Clock::fromDb($b['starts_at'])->getTimestamp(), 60);
            $db->exec("UPDATE reschedule_proposals SET status = 'superseded' WHERE booking_id = ? AND status = 'pending'", [$bookingId]);
            $pid = $db->insert('reschedule_proposals', ['booking_id' => $bookingId, 'proposed_by' => $userId, 'new_starts_at' => Clock::db($start),
                'new_duration_minutes' => $duration, 'note' => $note, 'created_at' => Clock::db()]);
            $other = (int) $b['client_id'] === $userId ? (int) $b['musician_id'] : (int) $b['client_id'];
            NotificationService::send($other, 'reschedule_proposed', 'New time proposed',
                'New start: ' . $start->setTimezone(new \DateTimeZone($b['timezone']))->format('D j M Y, H:i') . '. Accept or decline in the app.',
                ['booking_id' => $bookingId, 'proposal_id' => $pid], "reschedule:{$pid}");
        });
        return $this->detail($bookingId, $userId);
    }

    public function respondReschedule(int $userId, int $bookingId, int $proposalId, array $input): array
    {
        $v = new Validator($input);
        $action = $v->enum('action', ['accept', 'decline', 'withdraw']);
        $v->done();
        $db = Db::get();
        $musicianId = (int) $db->value('SELECT musician_id FROM bookings WHERE id = ?', [$bookingId]);
        $db->tx(function (Db $db) use ($userId, $bookingId, $proposalId, $action, $musicianId) {
            $db->one('SELECT user_id FROM musician_profiles WHERE user_id = ? FOR UPDATE', [$musicianId]);
            $b = $this->lockParticipant($db, $bookingId, $userId);
            $p = $db->one('SELECT * FROM reschedule_proposals WHERE id = ? AND booking_id = ? FOR UPDATE', [$proposalId, $bookingId]);
            if ($p === null || $p['status'] !== 'pending') {
                throw ApiError::conflict('proposal_closed', 'This proposal is no longer pending.');
            }
            $byProposer = (int) $p['proposed_by'] === $userId;
            if ($action === 'withdraw' ? !$byProposer : $byProposer) {
                throw ApiError::forbidden($byProposer ? 'The other party must answer your proposal.' : 'Only the proposer can withdraw.');
            }
            $now = Clock::db();
            if ($action !== 'accept') {
                $db->exec('UPDATE reschedule_proposals SET status = ?, responded_at = ? WHERE id = ?', [$action === 'withdraw' ? 'withdrawn' : 'declined', $now, $proposalId]);
            } else {
                if ($b['status'] !== 'confirmed') {
                    throw ApiError::conflict('bad_state', 'Only confirmed bookings can be rescheduled.');
                }
                $start = Clock::fromDb($p['new_starts_at']);
                if ($start <= Clock::now()) {
                    throw ApiError::conflict('proposal_expired', 'The proposed time has already passed.');
                }
                if (self::hasConflict($db, (int) $b['musician_id'], $start, (int) $p['new_duration_minutes'], (int) $b['setup_minutes'], $bookingId)) {
                    throw ApiError::conflict('schedule_conflict', 'The musician has another booking at the proposed time.');
                }
                $db->exec('UPDATE bookings SET starts_at = ?, ends_at = ?, reminder_sent_at = NULL, version = version + 1, updated_at = ? WHERE id = ?',
                    [Clock::db($start), Clock::db(Clock::plusMinutes($start, (int) $p['new_duration_minutes'])), $now, $bookingId]);
                $db->exec("UPDATE reschedule_proposals SET status = 'accepted', responded_at = ? WHERE id = ?", [$now, $proposalId]);
                $db->insert('booking_events', ['booking_id' => $bookingId, 'actor_id' => $userId, 'event' => 'rescheduled',
                    'detail_json' => json_encode(['from' => Clock::iso($b['starts_at']), 'to' => Clock::iso($p['new_starts_at'])]), 'created_at' => $now]);
            }
            if ($action !== 'withdraw') {
                NotificationService::send((int) $p['proposed_by'], 'reschedule_answered', $action === 'accept' ? 'New time accepted' : 'New time declined',
                    $action === 'accept' ? 'The booking has been moved.' : 'The original time still stands.', ['booking_id' => $bookingId], "reschedule_ans:{$proposalId}");
            }
        });
        return $this->detail($bookingId, $userId);
    }

    public function events(int $bookingId, int $viewerId): array
    {
        $this->participantBooking($bookingId, $viewerId);
        return array_map(fn ($e) => ['event' => $e['event'], 'at' => Clock::iso($e['created_at']), 'by_me' => (int) $e['actor_id'] === $viewerId,
            'detail' => json_decode((string) $e['detail_json'], true)],
            Db::get()->all('SELECT * FROM booking_events WHERE booking_id = ? ORDER BY id', [$bookingId]));
    }

    public function canDispute(array $b): bool
    {
        $now = Clock::now();
        if (in_array($b['status'], ['in_progress'], true)) {
            return true;
        }
        if ($b['status'] === 'confirmed') {
            return Clock::fromDb($b['starts_at']) <= $now;
        }
        if ($b['status'] === 'completed') {
            return $b['dispute_window_ends_at'] !== null && Clock::fromDb($b['dispute_window_ends_at']) > $now;
        }
        return false;
    }

    public function chatOpen(array $b): bool
    {
        if (in_array($b['status'], self::ACTIVE, true)) {
            return true;
        }
        $ref = $b['status'] === 'completed' ? $b['completed_at'] : $b['updated_at'];
        return $ref !== null && Clock::fromDb($ref) > Clock::now()->modify('-14 days');
    }

    public function participantBooking(int $bookingId, int $userId): array
    {
        $b = Db::get()->one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        if ($b === null || ((int) $b['client_id'] !== $userId && (int) $b['musician_id'] !== $userId)) {
            throw ApiError::notFound('Booking');
        }
        return $b;
    }

    private function lockParticipant(Db $db, int $bookingId, int $userId): array
    {
        $b = $db->one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
        if ($b === null || ((int) $b['client_id'] !== $userId && (int) $b['musician_id'] !== $userId)) {
            throw ApiError::notFound('Booking');
        }
        return $b;
    }

    private function paymentStatus(int $bookingId): string
    {
        $s = Db::get()->value("SELECT status FROM payments WHERE booking_id = ? ORDER BY FIELD(status,'paid','partially_refunded','refunded','pending','failed','cancelled'), id DESC LIMIT 1", [$bookingId]);
        return $s ?? 'unpaid';
    }
}
