<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Support\Clock;
use Blissful\Support\Settings;
use Blissful\Support\Validator;

final class RequestService
{
    public const EVENT_TYPES = ['church_service', 'wedding', 'funeral', 'concert', 'corporate', 'conference', 'birthday', 'private_party',
        'recording_session', 'rehearsal', 'festival', 'other'];

    public function create(int $clientId, array $input): array
    {
        $data = $this->validate($input, true);
        $publish = ($input['publish'] ?? false) === true;
        $id = Db::get()->tx(function (Db $db) use ($clientId, $data) {
            $now = Clock::db();
            $id = $db->insert('requests', $data['request'] + ['client_id' => $clientId, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
            foreach ($data['positions'] as $p) {
                $db->insert('request_positions', $p + ['request_id' => $id]);
            }
            return $id;
        });
        if ($publish) {
            return $this->publish($clientId, $id);
        }
        return $this->detail($id, $clientId);
    }

    /** Drafts can be fully edited. Open requests only allow non-commercial details and a later deadline. */
    public function update(int $clientId, int $requestId, array $input): array
    {
        $req = $this->own($clientId, $requestId);
        if ($req['status'] === 'draft') {
            $data = $this->validate($input, true);
            Db::get()->tx(function (Db $db) use ($requestId, $data) {
                $sets = [];
                $params = [];
                foreach ($data['request'] as $k => $v) {
                    $sets[] = "{$k} = ?";
                    $params[] = $v;
                }
                $params[] = Clock::db();
                $params[] = $requestId;
                $db->exec('UPDATE requests SET ' . implode(', ', $sets) . ', updated_at = ? WHERE id = ?', $params);
                $db->exec('DELETE FROM request_positions WHERE request_id = ?', [$requestId]);
                foreach ($data['positions'] as $p) {
                    $db->insert('request_positions', $p + ['request_id' => $requestId]);
                }
            });
            return $this->detail($requestId, $clientId);
        }
        if ($req['status'] !== 'open') {
            throw ApiError::conflict('not_editable', 'This request can no longer be edited.');
        }
        $v = new Validator($input);
        $desc = $v->string('description', 0, 3000, false, $req['description']);
        $equip = $v->string('equipment_available', 0, 1000, false, $req['equipment_available']);
        $travel = $v->string('travel_notes', 0, 1000, false, $req['travel_notes']);
        $rehearsal = $v->string('rehearsal_notes', 0, 1000, false, $req['rehearsal_notes']);
        $venueAddr = $v->string('venue_address_private', 0, 300, false, $req['venue_address_private']);
        $deadline = $v->datetime('offer_deadline', false);
        $v->done();
        if ($deadline !== null) {
            if ($deadline <= Clock::fromDb($req['offer_deadline']) || $deadline > Clock::fromDb($req['starts_at'])) {
                throw ApiError::validation(['offer_deadline' => 'You can only extend the deadline, up to the event start.']);
            }
        }
        Db::get()->exec('UPDATE requests SET description=?, equipment_available=?, travel_notes=?, rehearsal_notes=?, venue_address_private=?,
                         offer_deadline=?, updated_at=? WHERE id=?',
            [$desc, $equip, $travel, $rehearsal, $venueAddr, $deadline ? Clock::db($deadline) : $req['offer_deadline'], Clock::db(), $requestId]);
        return $this->detail($requestId, $clientId);
    }

    public function publish(int $clientId, int $requestId): array
    {
        $req = $this->own($clientId, $requestId);
        if ($req['status'] !== 'draft') {
            throw ApiError::conflict('not_draft', 'Only drafts can be published.');
        }
        if (Clock::fromDb($req['offer_deadline']) <= Clock::now() || Clock::fromDb($req['starts_at']) <= Clock::now()) {
            throw ApiError::validation(['offer_deadline' => 'Deadline and event time must be in the future.']);
        }
        Db::get()->exec("UPDATE requests SET status = 'open', published_at = ?, updated_at = ? WHERE id = ? AND status = 'draft'",
            [Clock::db(), Clock::db(), $requestId]);
        $this->notifyMatches($requestId);
        return $this->detail($requestId, $clientId);
    }

    /** Cancels a request with no bookings, or closes the remaining open positions of a partly booked one. */
    public function cancel(int $clientId, int $requestId): array
    {
        Db::get()->tx(function (Db $db) use ($clientId, $requestId) {
            $req = $db->one('SELECT * FROM requests WHERE id = ? AND client_id = ? FOR UPDATE', [$requestId, $clientId]);
            if ($req === null) {
                throw ApiError::notFound('Request');
            }
            if (!in_array($req['status'], ['draft', 'open', 'filled'], true)) {
                throw ApiError::conflict('not_cancellable', 'This request is already closed.');
            }
            $booked = (int) $db->value("SELECT COUNT(*) FROM bookings WHERE request_id = ? AND status IN ('confirmed','in_progress','disputed')", [$requestId]);
            $newStatus = $booked > 0 ? 'closed' : 'cancelled';
            if ($req['status'] === 'filled' && $booked > 0) {
                throw ApiError::conflict('has_bookings', 'All positions are booked. Cancel the individual bookings instead.');
            }
            $now = Clock::db();
            $db->exec('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?', [$newStatus, $now, $requestId]);
            $db->exec("UPDATE request_positions SET status = 'cancelled' WHERE request_id = ? AND status = 'open'", [$requestId]);
            foreach ($db->all("SELECT id, musician_id, price_minor, extras_minor, version FROM offers WHERE request_id = ? AND status = 'submitted'", [$requestId]) as $o) {
                $db->exec("UPDATE offers SET status = 'rejected', updated_at = ? WHERE id = ?", [$now, $o['id']]);
                OfferService::revision($db, (int) $o['id'], $clientId, 'rejected', (int) $o['price_minor'], (int) $o['extras_minor'], 'Request closed by client', (int) $o['version']);
                NotificationService::send((int) $o['musician_id'], 'offer_rejected', 'Request closed',
                    "The client closed \"{$req['title']}\".", ['request_id' => $requestId, 'offer_id' => (int) $o['id']], "offer_rejected:{$o['id']}");
            }
        });
        return $this->detail($requestId, $clientId);
    }

    public function mine(int $clientId, array $q): array
    {
        $status = $q['status'] ?? null;
        $params = [$clientId];
        $sql = 'SELECT * FROM requests WHERE client_id = ?';
        if (is_string($status) && in_array($status, ['draft', 'open', 'filled', 'closed', 'expired', 'cancelled'], true)) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        } elseif ($status === 'active') {
            $sql .= " AND status IN ('draft','open','filled')";
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $rows = Db::get()->all($sql . ' ORDER BY starts_at DESC, id DESC LIMIT 21 OFFSET ' . (($page - 1) * 20), $params);
        $more = count($rows) > 20;
        return ['items' => array_map(fn ($r) => $this->summary($r, $clientId), array_slice($rows, 0, 20)), 'page' => $page, 'has_more' => $more];
    }

    /** Open requests a musician can respond to: their instruments, open positions, live deadline, within travel range. */
    public function feed(int $musicianId, array $q): array
    {
        $db = Db::get();
        $m = $db->one('SELECT * FROM musician_profiles WHERE user_id = ?', [$musicianId]);
        if ($m === null) {
            throw ApiError::conflict('musician_profile_required', 'Create your musician profile to see requests.');
        }
        $params = [Clock::db(), Clock::db(), $musicianId, $musicianId, $musicianId, $musicianId];
        $where = "r.status = 'open' AND r.offer_deadline > ? AND r.starts_at > ? AND r.client_id <> ?
                  AND EXISTS (SELECT 1 FROM request_positions p JOIN musician_instruments mi ON mi.instrument_id = p.instrument_id
                              WHERE p.request_id = r.id AND p.status = 'open' AND mi.musician_id = ?)
                  AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = r.client_id AND b.blocked_id = ?) OR (b.blocker_id = ? AND b.blocked_id = r.client_id))";
        if (isset($q['instrument_id']) && ctype_digit((string) $q['instrument_id'])) {
            $where .= " AND EXISTS (SELECT 1 FROM request_positions p2 WHERE p2.request_id = r.id AND p2.status = 'open' AND p2.instrument_id = ?)";
            $params[] = (int) $q['instrument_id'];
        }
        if (isset($q['from']) && is_string($q['from']) && ($from = Clock::parseIso($q['from']))) {
            $where .= ' AND r.starts_at >= ?';
            $params[] = Clock::db($from);
        }
        if (isset($q['to']) && is_string($q['to']) && ($to = Clock::parseIso($q['to']))) {
            $where .= ' AND r.starts_at <= ?';
            $params[] = Clock::db($to);
        }
        $anywhere = ($q['anywhere'] ?? '') === '1';
        $rows = $db->all("SELECT r.* FROM requests r WHERE {$where} ORDER BY r.starts_at ASC, r.id ASC LIMIT 300", $params);
        $items = [];
        foreach ($rows as $r) {
            $dist = self::distanceKm($m['lat'], $m['lng'], $r['lat'], $r['lng']);
            $inRange = $dist !== null ? $dist <= (int) $m['travel_radius_km'] : strcasecmp(trim((string) $m['city']), trim((string) $r['city'])) === 0;
            if (!$anywhere && !$inRange) {
                continue;
            }
            $s = $this->summary($r, $musicianId);
            $s['distance_km'] = $dist !== null ? (int) round($dist) : null;
            $s['in_travel_range'] = $inRange;
            $s['my_offer_status'] = $db->value("SELECT status FROM offers WHERE request_id = ? AND musician_id = ? ORDER BY updated_at DESC LIMIT 1", [$r['id'], $musicianId]);
            $s['schedule_conflict'] = BookingService::hasConflict($db, $musicianId, Clock::fromDb($r['starts_at']),
                (int) $r['duration_minutes'], (int) $r['setup_minutes'], null);
            $items[] = $s;
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $slice = array_slice($items, ($page - 1) * 20, 21);
        return ['items' => array_slice($slice, 0, 20), 'page' => $page, 'has_more' => count($slice) > 20];
    }

    public function detail(int $requestId, int $viewerId): array
    {
        $db = Db::get();
        $r = $db->one('SELECT * FROM requests WHERE id = ?', [$requestId]);
        if ($r === null) {
            throw ApiError::notFound('Request');
        }
        $isOwner = (int) $r['client_id'] === $viewerId;
        $isBooked = !$isOwner && (bool) $db->value("SELECT 1 FROM bookings WHERE request_id = ? AND musician_id = ? AND status IN ('confirmed','in_progress','completed','disputed')", [$requestId, $viewerId]);
        $hasOffered = !$isOwner && (bool) $db->value('SELECT 1 FROM offers WHERE request_id = ? AND musician_id = ?', [$requestId, $viewerId]);
        if (!$isOwner && !$isBooked && !$hasOffered && $r['status'] !== 'open') {
            throw ApiError::notFound('Request');
        }
        if (!$isOwner && $db->value('SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)',
                [$r['client_id'], $viewerId, $viewerId, $r['client_id']])) {
            throw ApiError::notFound('Request');
        }
        $out = $this->summary($r, $viewerId);
        $out += [
            'description' => $r['description'],
            'setup_minutes' => (int) $r['setup_minutes'],
            'rehearsal_notes' => $r['rehearsal_notes'],
            'venue_name' => $r['venue_name'],
            'equipment_available' => $r['equipment_available'],
            'travel_notes' => $r['travel_notes'],
            'client' => (new ProfileService())->clientProfile((int) $r['client_id'], $isOwner),
            'is_owner' => $isOwner,
        ];
        if ($isOwner || $isBooked) {
            $out['venue_address'] = $r['venue_address_private'];
            if ($isOwner) {
                $out['lat'] = $r['lat'] !== null ? (float) $r['lat'] : null;
                $out['lng'] = $r['lng'] !== null ? (float) $r['lng'] : null;
            }
        }
        return $out;
    }

    public function summary(array $r, int $viewerId): array
    {
        $db = Db::get();
        $positions = $db->all('SELECT p.*, i.name AS instrument FROM request_positions p JOIN instruments i ON i.id = p.instrument_id WHERE p.request_id = ? ORDER BY p.id', [$r['id']]);
        $isOwner = (int) $r['client_id'] === $viewerId;
        return [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'event_type' => $r['event_type'],
            'status' => $r['status'],
            'starts_at' => Clock::iso($r['starts_at']),
            'timezone' => $r['timezone'],
            'duration_minutes' => (int) $r['duration_minutes'],
            'city' => $r['city'],
            'area' => $r['area'],
            'currency' => $r['currency'],
            'offer_deadline' => Clock::iso($r['offer_deadline']),
            'client_name' => (string) $db->value('SELECT name FROM client_profiles WHERE user_id = ?', [$r['client_id']]),
            'client_id' => (int) $r['client_id'],
            'positions' => array_map(function ($p) use ($db, $isOwner) {
                $pos = [
                    'id' => (int) $p['id'], 'instrument_id' => (int) $p['instrument_id'], 'instrument' => $p['instrument'],
                    'quantity' => (int) $p['quantity'], 'filled' => (int) $p['filled_count'], 'budget_minor' => (int) $p['budget_minor'],
                    'status' => $p['status'], 'notes' => $p['notes'],
                ];
                if ($isOwner) {
                    $pos['offer_count'] = (int) $db->value("SELECT COUNT(*) FROM offers WHERE position_id = ? AND status = 'submitted'", [$p['id']]);
                }
                return $pos;
            }, $positions),
            'total_budget_minor' => array_sum(array_map(fn ($p) => (int) $p['budget_minor'] * (int) $p['quantity'], $positions)),
            'created_at' => Clock::iso($r['created_at']),
        ];
    }

    /** Notifies musicians whose instruments match, who are in range and free at that time. Deduplicated per request. */
    public function notifyMatches(int $requestId): int
    {
        $db = Db::get();
        $r = $db->one('SELECT * FROM requests WHERE id = ?', [$requestId]);
        $candidates = $db->all("SELECT DISTINCT m.user_id, m.lat, m.lng, m.city, m.travel_radius_km
            FROM musician_profiles m JOIN users u ON u.id = m.user_id
            JOIN musician_instruments mi ON mi.musician_id = m.user_id
            JOIN request_positions p ON p.instrument_id = mi.instrument_id AND p.request_id = ? AND p.status = 'open'
            WHERE u.status = 'active' AND m.accepting_bookings = 1 AND m.user_id <> ?
              AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = m.user_id) OR (b.blocker_id = m.user_id AND b.blocked_id = ?))
            LIMIT 500", [$requestId, $r['client_id'], $r['client_id'], $r['client_id']]);
        $start = Clock::fromDb($r['starts_at']);
        $end = Clock::plusMinutes($start, (int) $r['duration_minutes']);
        $sent = 0;
        foreach ($candidates as $c) {
            $dist = self::distanceKm($c['lat'], $c['lng'], $r['lat'], $r['lng']);
            $inRange = $dist !== null ? $dist <= (int) $c['travel_radius_km'] : strcasecmp(trim((string) $c['city']), trim((string) $r['city'])) === 0;
            if (!$inRange) {
                continue;
            }
            $mid = (int) $c['user_id'];
            if (BookingService::hasConflict($db, $mid, $start, (int) $r['duration_minutes'], (int) $r['setup_minutes'], null)) {
                continue;
            }
            $blocked = $db->value('SELECT 1 FROM availability_blocks WHERE musician_id = ? AND starts_at < ? AND ends_at > ?',
                [$mid, Clock::db($end), Clock::db($start)]);
            if ($blocked) {
                continue;
            }
            if (NotificationService::send($mid, 'request_match', 'New request: ' . $r['title'],
                $r['city'] . ' · ' . $start->setTimezone(new \DateTimeZone($r['timezone']))->format('D j M, H:i'),
                ['request_id' => $requestId], "match:{$requestId}") !== null) {
                $sent++;
            }
        }
        return $sent;
    }

    public static function distanceKm(mixed $lat1, mixed $lng1, mixed $lat2, mixed $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }
        $toRad = fn ($d) => (float) $d * M_PI / 180;
        $dLat = $toRad($lat2) - $toRad($lat1);
        $dLng = $toRad($lng2) - $toRad($lng1);
        $a = sin($dLat / 2) ** 2 + cos($toRad($lat1)) * cos($toRad($lat2)) * sin($dLng / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function own(int $clientId, int $requestId): array
    {
        $r = Db::get()->one('SELECT * FROM requests WHERE id = ? AND client_id = ?', [$requestId, $clientId]);
        if ($r === null) {
            throw ApiError::notFound('Request');
        }
        return $r;
    }

    private function validate(array $input, bool $full): array
    {
        $v = new Validator($input);
        $title = $v->string('title', 4, 150);
        $eventType = $v->enum('event_type', self::EVENT_TYPES);
        $desc = $v->string('description', 0, 3000, false);
        $startsAt = $v->datetime('starts_at');
        $tz = $v->timezone('timezone');
        $duration = $v->int('duration_minutes', 15, 1440);
        $setup = $v->int('setup_minutes', 0, 480, false, 0);
        $rehearsal = $v->string('rehearsal_notes', 0, 1000, false);
        $city = $v->string('city', 2, 80);
        $area = $v->string('area', 0, 80, false);
        $lat = $v->decimal('lat', -90, 90);
        $lng = $v->decimal('lng', -180, 180);
        $venue = $v->string('venue_name', 0, 150, false);
        $venueAddr = $v->string('venue_address_private', 0, 300, false);
        $currency = $v->currency('currency');
        $equip = $v->string('equipment_available', 0, 1000, false);
        $travel = $v->string('travel_notes', 0, 1000, false);
        $deadline = $v->datetime('offer_deadline');
        $positionsIn = $v->raw('positions');
        $positions = [];
        if (!is_array($positionsIn) || !array_is_list($positionsIn) || count($positionsIn) < 1 || count($positionsIn) > 10) {
            $v->addError('positions', 'Add between 1 and 10 positions.');
        } else {
            $seen = [];
            foreach ($positionsIn as $i => $p) {
                $pv = new Validator(is_array($p) ? $p : []);
                $iid = $pv->int('instrument_id', 1, PHP_INT_MAX);
                $qty = $pv->int('quantity', 1, 10, false, 1);
                $budget = $pv->money('budget_minor');
                $notes = $pv->string('notes', 0, 500, false);
                try {
                    $pv->done();
                } catch (ApiError $e) {
                    foreach ($e->fields as $k => $msg) {
                        $v->addError("positions.{$i}.{$k}", $msg);
                    }
                    continue;
                }
                if (isset($seen[$iid])) {
                    $v->addError("positions.{$i}.instrument_id", 'Combine duplicate instruments into one position with a quantity.');
                }
                $seen[$iid] = true;
                $positions[] = ['instrument_id' => $iid, 'quantity' => $qty, 'budget_minor' => $budget, 'notes' => $notes];
            }
            if ($seen !== []) {
                $in = implode(',', array_map('intval', array_keys($seen)));
                if ((int) Db::get()->value("SELECT COUNT(*) FROM instruments WHERE is_active = 1 AND id IN ($in)") !== count($seen)) {
                    $v->addError('positions', 'Contains an unknown instrument.');
                }
            }
        }
        $lead = Settings::int('min_lead_time_hours', 2);
        if ($startsAt !== null && $startsAt < Clock::now()->modify("+{$lead} hours")) {
            $v->addError('starts_at', "The event must start at least {$lead} hours from now.");
        }
        if ($startsAt !== null && $startsAt > Clock::now()->modify('+18 months')) {
            $v->addError('starts_at', 'Events can be booked up to 18 months ahead.');
        }
        if ($deadline !== null && $startsAt !== null && ($deadline <= Clock::now() || $deadline > $startsAt)) {
            $v->addError('offer_deadline', 'The offer deadline must be in the future and before the event starts.');
        }
        $v->done();
        return [
            'request' => [
                'title' => $title, 'event_type' => $eventType, 'description' => $desc, 'starts_at' => Clock::db($startsAt), 'timezone' => $tz,
                'duration_minutes' => $duration, 'setup_minutes' => $setup, 'rehearsal_notes' => $rehearsal, 'city' => $city, 'area' => $area,
                'lat' => $lat, 'lng' => $lng, 'venue_name' => $venue, 'venue_address_private' => $venueAddr, 'currency' => $currency,
                'equipment_available' => $equip, 'travel_notes' => $travel, 'offer_deadline' => Clock::db($deadline),
            ],
            'positions' => $positions,
        ];
    }
}
