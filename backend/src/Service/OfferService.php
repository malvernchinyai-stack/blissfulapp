<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Support\Clock;
use Blissful\Support\Money;
use Blissful\Support\Settings;
use Blissful\Support\Validator;

final class OfferService
{
    public function submit(int $musicianId, int $requestId, array $input): array
    {
        $v = new Validator($input);
        $positionId = $v->int('position_id', 1, PHP_INT_MAX);
        $acceptBudget = $v->bool('accept_budget');
        $price = $acceptBudget ? null : $v->money('price_minor');
        $extras = $v->money('extras_minor', false) ?? 0;
        $extrasNote = $v->string('extras_note', 0, 300, false);
        $included = $v->string('included_services', 0, 1000, false);
        $message = $v->string('message', 0, 1000, false);
        $v->done();
        if ($extras > 0 && ($extrasNote === null || $extrasNote === '')) {
            throw ApiError::validation(['extras_note' => 'Explain what the additional charges are for.']);
        }

        $db = Db::get();
        if (!$db->value('SELECT 1 FROM musician_profiles WHERE user_id = ?', [$musicianId])) {
            throw ApiError::conflict('musician_profile_required', 'Create your musician profile before making offers.');
        }
        $offerId = $db->tx(function (Db $db) use ($musicianId, $requestId, $positionId, $price, $extras, $extrasNote, $included, $message) {
            $r = $db->one('SELECT * FROM requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($r === null) {
                throw ApiError::notFound('Request');
            }
            if ((int) $r['client_id'] === $musicianId) {
                throw ApiError::forbidden('You cannot make an offer on your own request.');
            }
            if ($r['status'] !== 'open' || Clock::fromDb($r['offer_deadline']) <= Clock::now()) {
                throw ApiError::conflict('request_closed', 'This request is no longer accepting offers.');
            }
            if ($db->value('SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)',
                [$r['client_id'], $musicianId, $musicianId, $r['client_id']])) {
                throw ApiError::forbidden('You cannot make an offer on this request.');
            }
            $p = $db->one('SELECT * FROM request_positions WHERE id = ? AND request_id = ?', [$positionId, $requestId]);
            if ($p === null) {
                throw ApiError::validation(['position_id' => 'Unknown position for this request.']);
            }
            if ($p['status'] !== 'open') {
                throw ApiError::conflict('position_filled', 'This position has already been filled.');
            }
            if (!$db->value('SELECT 1 FROM musician_instruments WHERE musician_id = ? AND instrument_id = ?', [$musicianId, $p['instrument_id']])) {
                throw ApiError::forbidden('This position needs an instrument that is not on your profile.');
            }
            if ($db->value("SELECT 1 FROM offers WHERE request_id = ? AND musician_id = ? AND position_id <> ? AND status IN ('submitted','accepted')",
                [$requestId, $musicianId, $positionId])) {
                throw ApiError::conflict('already_offered', 'You already have an offer on another position of this request.');
            }
            if (BookingService::hasConflict($db, $musicianId, Clock::fromDb($r['starts_at']), (int) $r['duration_minutes'], (int) $r['setup_minutes'], null)) {
                throw ApiError::conflict('schedule_conflict', 'You already have a confirmed booking at that time.');
            }
            $open = (int) $db->value("SELECT COUNT(*) FROM offers WHERE musician_id = ? AND status = 'submitted'", [$musicianId]);
            if ($open >= Settings::int('max_open_offers_per_musician', 25)) {
                throw ApiError::conflict('too_many_offers', 'You have too many open offers. Withdraw some before making new ones.');
            }
            $price ??= (int) $p['budget_minor'];
            $now = Clock::db();
            $existing = $db->one('SELECT * FROM offers WHERE position_id = ? AND musician_id = ? FOR UPDATE', [$positionId, $musicianId]);
            if ($existing !== null) {
                if (in_array($existing['status'], ['submitted', 'accepted'], true)) {
                    throw ApiError::conflict('already_offered', 'You already made an offer for this position. Edit it instead.');
                }
                $version = (int) $existing['version'] + 1;
                $db->exec("UPDATE offers SET price_minor=?, extras_minor=?, extras_note=?, included_services=?, message=?, counter_price_minor=NULL,
                           counter_note=NULL, status='submitted', version=?, updated_at=? WHERE id=?",
                    [$price, $extras, $extrasNote, $included, $message, $version, $now, $existing['id']]);
                $id = (int) $existing['id'];
            } else {
                $version = 1;
                $id = $db->insert('offers', [
                    'request_id' => $requestId, 'position_id' => $positionId, 'musician_id' => $musicianId, 'price_minor' => $price,
                    'extras_minor' => $extras, 'extras_note' => $extrasNote, 'currency' => $r['currency'], 'included_services' => $included,
                    'message' => $message, 'status' => 'submitted', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            self::revision($db, $id, $musicianId, 'created', $price, $extras, $message, $version);
            $stage = (string) $db->value('SELECT stage_name FROM musician_profiles WHERE user_id = ?', [$musicianId]);
            NotificationService::send((int) $r['client_id'], 'offer_received', 'New offer for ' . $r['title'],
                $stage . ' offered ' . Money::format($price + $extras, $r['currency']), ['request_id' => $requestId, 'offer_id' => $id],
                "offer:{$id}:v{$version}");
            return $id;
        });
        return $this->view($offerId, $musicianId);
    }

    public function edit(int $musicianId, int $offerId, array $input): array
    {
        $v = new Validator($input);
        $price = $v->money('price_minor');
        $extras = $v->money('extras_minor', false) ?? 0;
        $extrasNote = $v->string('extras_note', 0, 300, false);
        $included = $v->string('included_services', 0, 1000, false);
        $message = $v->string('message', 0, 1000, false);
        $v->done();
        if ($extras > 0 && ($extrasNote === null || $extrasNote === '')) {
            throw ApiError::validation(['extras_note' => 'Explain what the additional charges are for.']);
        }
        Db::get()->tx(function (Db $db) use ($musicianId, $offerId, $price, $extras, $extrasNote, $included, $message) {
            $o = $this->lockOffer($db, $offerId);
            if ((int) $o['musician_id'] !== $musicianId) {
                throw ApiError::notFound('Offer');
            }
            $this->assertEditable($db, $o);
            $version = (int) $o['version'] + 1;
            $db->exec('UPDATE offers SET price_minor=?, extras_minor=?, extras_note=?, included_services=?, message=?, counter_price_minor=NULL,
                       counter_note=NULL, version=?, updated_at=? WHERE id=?',
                [$price, $extras, $extrasNote, $included, $message, $version, Clock::db(), $offerId]);
            self::revision($db, $offerId, $musicianId, 'edited', $price, $extras, $message, $version);
            $clientId = (int) $db->value('SELECT client_id FROM requests WHERE id = ?', [$o['request_id']]);
            NotificationService::send($clientId, 'offer_updated', 'An offer was updated', 'New total: ' . Money::format($price + $extras, $o['currency']),
                ['request_id' => (int) $o['request_id'], 'offer_id' => $offerId], "offer:{$offerId}:v{$version}");
        });
        return $this->view($offerId, $musicianId);
    }

    public function withdraw(int $musicianId, int $offerId): array
    {
        Db::get()->tx(function (Db $db) use ($musicianId, $offerId) {
            $o = $this->lockOffer($db, $offerId);
            if ((int) $o['musician_id'] !== $musicianId) {
                throw ApiError::notFound('Offer');
            }
            if ($o['status'] !== 'submitted') {
                throw ApiError::conflict('offer_not_open', 'Only open offers can be withdrawn.');
            }
            $db->exec("UPDATE offers SET status = 'withdrawn', updated_at = ? WHERE id = ?", [Clock::db(), $offerId]);
            self::revision($db, $offerId, $musicianId, 'withdrawn', (int) $o['price_minor'], (int) $o['extras_minor'], null, (int) $o['version']);
            $clientId = (int) $db->value('SELECT client_id FROM requests WHERE id = ?', [$o['request_id']]);
            NotificationService::send($clientId, 'offer_withdrawn', 'An offer was withdrawn', 'A musician withdrew their offer.',
                ['request_id' => (int) $o['request_id'], 'offer_id' => $offerId], "offer_withdrawn:{$offerId}:v{$o['version']}");
        });
        return $this->view($offerId, $musicianId);
    }

    /** Client proposes a different price. Terms change only if the musician explicitly accepts. */
    public function counter(int $clientId, int $offerId, array $input): array
    {
        $v = new Validator($input);
        $price = $v->money('price_minor');
        $note = $v->string('note', 0, 300, false);
        $v->done();
        Db::get()->tx(function (Db $db) use ($clientId, $offerId, $price, $note) {
            $o = $this->lockOffer($db, $offerId);
            $this->assertClientOwns($db, $o, $clientId);
            $this->assertEditable($db, $o);
            if ($price === (int) $o['price_minor']) {
                throw ApiError::validation(['price_minor' => 'That is already the offered price.']);
            }
            $db->exec('UPDATE offers SET counter_price_minor = ?, counter_note = ?, updated_at = ? WHERE id = ?', [$price, $note, Clock::db(), $offerId]);
            self::revision($db, $offerId, $clientId, 'counter_proposed', $price, (int) $o['extras_minor'], $note, (int) $o['version']);
            NotificationService::send((int) $o['musician_id'], 'counter_proposed', 'The client proposed a different price',
                'Proposed: ' . Money::format($price, $o['currency']) . '. Accept or decline in the app.',
                ['request_id' => (int) $o['request_id'], 'offer_id' => $offerId], 'counter:' . $offerId . ':' . sha1($price . '|' . $o['version'] . '|' . microtime()));
        });
        return $this->view($offerId, $clientId);
    }

    public function respondCounter(int $musicianId, int $offerId, array $input): array
    {
        $v = new Validator($input);
        $accept = $v->bool('accept');
        $v->done();
        Db::get()->tx(function (Db $db) use ($musicianId, $offerId, $accept) {
            $o = $this->lockOffer($db, $offerId);
            if ((int) $o['musician_id'] !== $musicianId) {
                throw ApiError::notFound('Offer');
            }
            $this->assertEditable($db, $o);
            if ($o['counter_price_minor'] === null) {
                throw ApiError::conflict('no_counter', 'There is no pending counter-proposal.');
            }
            $counter = (int) $o['counter_price_minor'];
            if ($accept) {
                $version = (int) $o['version'] + 1;
                $db->exec('UPDATE offers SET price_minor = ?, counter_price_minor = NULL, counter_note = NULL, version = ?, updated_at = ? WHERE id = ?',
                    [$counter, $version, Clock::db(), $offerId]);
                self::revision($db, $offerId, $musicianId, 'counter_accepted', $counter, (int) $o['extras_minor'], null, $version);
            } else {
                $version = (int) $o['version'];
                $db->exec('UPDATE offers SET counter_price_minor = NULL, counter_note = NULL, updated_at = ? WHERE id = ?', [Clock::db(), $offerId]);
                self::revision($db, $offerId, $musicianId, 'counter_declined', (int) $o['price_minor'], (int) $o['extras_minor'], null, $version);
            }
            $clientId = (int) $db->value('SELECT client_id FROM requests WHERE id = ?', [$o['request_id']]);
            NotificationService::send($clientId, 'counter_answered', $accept ? 'Your price was accepted' : 'Your price was declined',
                $accept ? 'The offer now stands at ' . Money::format($counter + (int) $o['extras_minor'], $o['currency']) . '. You can confirm it.' : 'The original offer still stands.',
                ['request_id' => (int) $o['request_id'], 'offer_id' => $offerId], "counter_answer:{$offerId}:{$version}:" . ($accept ? 'y' : 'n') . ':' . $counter);
        });
        return $this->view($offerId, $musicianId);
    }

    public function reject(int $clientId, int $offerId): array
    {
        Db::get()->tx(function (Db $db) use ($clientId, $offerId) {
            $o = $this->lockOffer($db, $offerId);
            $this->assertClientOwns($db, $o, $clientId);
            if ($o['status'] !== 'submitted') {
                throw ApiError::conflict('offer_not_open', 'This offer is no longer open.');
            }
            $db->exec("UPDATE offers SET status = 'rejected', updated_at = ? WHERE id = ?", [Clock::db(), $offerId]);
            self::revision($db, $offerId, $clientId, 'rejected', (int) $o['price_minor'], (int) $o['extras_minor'], null, (int) $o['version']);
            NotificationService::send((int) $o['musician_id'], 'offer_rejected', 'Offer not selected', 'The client chose not to go ahead with your offer.',
                ['request_id' => (int) $o['request_id'], 'offer_id' => $offerId], "offer_rejected:{$offerId}");
        });
        return $this->view($offerId, $clientId);
    }

    /**
     * Atomically turns an offer into a confirmed booking.
     * Lock order (musician profile → position → offer) is fixed to avoid deadlocks; every capacity and
     * conflict rule is re-checked under the locks, and UNIQUE(bookings.offer_id) backs it up.
     */
    public function accept(int $clientId, int $offerId, array $input): array
    {
        $v = new Validator($input);
        $expectedVersion = $v->int('expected_version', 1, PHP_INT_MAX);
        $v->done();
        $db = Db::get();
        $peek = $db->one('SELECT musician_id, position_id FROM offers WHERE id = ?', [$offerId]);
        if ($peek === null) {
            throw ApiError::notFound('Offer');
        }
        $bookingId = $db->tx(function (Db $db) use ($clientId, $offerId, $expectedVersion, $peek) {
            $db->one('SELECT user_id FROM musician_profiles WHERE user_id = ? FOR UPDATE', [$peek['musician_id']]);
            $pos = $db->one('SELECT * FROM request_positions WHERE id = ? FOR UPDATE', [$peek['position_id']]);
            $o = $this->lockOffer($db, $offerId);
            $r = $db->one('SELECT * FROM requests WHERE id = ? FOR UPDATE', [$o['request_id']]);
            if ((int) $r['client_id'] !== $clientId) {
                throw ApiError::notFound('Offer');
            }
            if ($o['status'] !== 'submitted') {
                throw ApiError::conflict('offer_not_open', 'This offer is no longer available.');
            }
            if ((int) $o['version'] !== $expectedVersion) {
                throw ApiError::conflict('offer_changed', 'The offer changed after you opened it. Please review the new terms.');
            }
            if ($o['counter_price_minor'] !== null) {
                throw ApiError::conflict('counter_pending', 'Your counter-proposal is waiting for the musician. Withdraw it or wait for an answer.');
            }
            if ($r['status'] !== 'open' || Clock::fromDb($r['starts_at']) <= Clock::now() || Clock::fromDb($r['offer_deadline']) <= Clock::now()) {
                throw ApiError::conflict('request_closed', 'This request has closed. Extend the deadline to keep choosing.');
            }
            if ($pos['status'] !== 'open' || (int) $pos['filled_count'] >= (int) $pos['quantity']) {
                throw ApiError::conflict('position_filled', 'All places for this position are already filled.');
            }
            $musicianId = (int) $o['musician_id'];
            if ($db->value("SELECT 1 FROM users WHERE id = ? AND status = 'active'", [$musicianId]) === null) {
                throw ApiError::conflict('musician_unavailable', 'This musician is no longer available.');
            }
            if ($db->value("SELECT 1 FROM bookings WHERE request_id = ? AND musician_id = ? AND status IN ('confirmed','in_progress','completed','disputed')",
                [$r['id'], $musicianId])) {
                throw ApiError::conflict('already_booked', 'This musician is already booked for this event.');
            }
            $start = Clock::fromDb($r['starts_at']);
            if (BookingService::hasConflict($db, $musicianId, $start, (int) $r['duration_minutes'], (int) $r['setup_minutes'], null)) {
                throw ApiError::conflict('schedule_conflict', 'The musician has another booking at this time.');
            }
            $feeBps = Settings::int('platform_fee_bps', 1000);
            $b = Money::breakdown((int) $o['price_minor'], (int) $o['extras_minor'], $feeBps);
            $now = Clock::db();
            $terms = [
                'request_title' => $r['title'], 'event_type' => $r['event_type'], 'instrument_position_id' => (int) $pos['id'],
                'starts_at' => Clock::iso($r['starts_at']), 'timezone' => $r['timezone'], 'duration_minutes' => (int) $r['duration_minutes'],
                'setup_minutes' => (int) $r['setup_minutes'], 'included_services' => $o['included_services'], 'extras_note' => $o['extras_note'],
                'offer_version' => (int) $o['version'], 'price' => $b, 'currency' => $o['currency'],
                'cancellation_policy' => Settings::get('cancellation_policy'), 'dispute_window_hours' => Settings::int('dispute_window_hours', 48),
                'accepted_at' => Clock::iso($now),
            ];
            $bookingId = $db->insert('bookings', [
                'request_id' => $r['id'], 'position_id' => $pos['id'], 'offer_id' => $offerId, 'client_id' => $clientId, 'musician_id' => $musicianId,
                'starts_at' => $r['starts_at'], 'ends_at' => Clock::db(Clock::plusMinutes($start, (int) $r['duration_minutes'])),
                'setup_minutes' => (int) $r['setup_minutes'], 'timezone' => $r['timezone'], 'price_minor' => $b['price_minor'],
                'extras_minor' => $b['extras_minor'], 'fee_minor' => $b['fee_minor'], 'total_minor' => $b['total_minor'], 'currency' => $o['currency'],
                'fee_bps' => $feeBps, 'terms_json' => json_encode($terms), 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->exec("UPDATE offers SET status = 'accepted', updated_at = ? WHERE id = ?", [$now, $offerId]);
            self::revision($db, $offerId, $clientId, 'accepted', (int) $o['price_minor'], (int) $o['extras_minor'], null, (int) $o['version']);
            $filled = (int) $pos['filled_count'] + 1;
            $posFull = $filled >= (int) $pos['quantity'];
            $db->exec('UPDATE request_positions SET filled_count = ?, status = ? WHERE id = ?', [$filled, $posFull ? 'filled' : 'open', $pos['id']]);
            // The musician can only hold one place per event: close their other open offers on this request.
            $db->exec("UPDATE offers SET status = 'rejected', updated_at = ? WHERE request_id = ? AND musician_id = ? AND status = 'submitted'", [$now, $r['id'], $musicianId]);
            if ($posFull) {
                foreach ($db->all("SELECT id, musician_id, price_minor, extras_minor, version FROM offers WHERE position_id = ? AND status = 'submitted'", [$pos['id']]) as $other) {
                    $db->exec("UPDATE offers SET status = 'rejected', updated_at = ? WHERE id = ?", [$now, $other['id']]);
                    self::revision($db, (int) $other['id'], $clientId, 'rejected', (int) $other['price_minor'], (int) $other['extras_minor'], 'Position filled', (int) $other['version']);
                    NotificationService::send((int) $other['musician_id'], 'offer_rejected', 'Position filled',
                        "Another musician was chosen for \"{$r['title']}\".", ['request_id' => (int) $r['id'], 'offer_id' => (int) $other['id']], "offer_rejected:{$other['id']}");
                }
            }
            $openLeft = (int) $db->value("SELECT COUNT(*) FROM request_positions WHERE request_id = ? AND status = 'open'", [$r['id']]);
            if ($openLeft === 0) {
                $db->exec("UPDATE requests SET status = 'filled', updated_at = ? WHERE id = ?", [$now, $r['id']]);
            }
            $db->insert('booking_events', ['booking_id' => $bookingId, 'actor_id' => $clientId, 'event' => 'confirmed', 'detail_json' => json_encode(['offer_version' => (int) $o['version']]), 'created_at' => $now]);
            NotificationService::send($musicianId, 'offer_accepted', 'You\'re booked! 🎶', "Your offer for \"{$r['title']}\" was accepted.",
                ['booking_id' => $bookingId], "booking_confirmed:{$bookingId}:m");
            NotificationService::send($clientId, 'booking_confirmed', 'Booking confirmed', "Your booking for \"{$r['title']}\" is confirmed.",
                ['booking_id' => $bookingId], "booking_confirmed:{$bookingId}:c");
            return $bookingId;
        });
        return (new BookingService())->detail($bookingId, $clientId);
    }

    public function listForRequest(int $clientId, int $requestId, array $q): array
    {
        $db = Db::get();
        $r = $db->one('SELECT * FROM requests WHERE id = ? AND client_id = ?', [$requestId, $clientId]);
        if ($r === null) {
            throw ApiError::notFound('Request');
        }
        $params = [$requestId];
        $sql = 'SELECT o.*, m.stage_name, m.years_experience, m.rating_sum, m.rating_count, m.completed_count, m.photo_file_id, m.city
                FROM offers o JOIN musician_profiles m ON m.user_id = o.musician_id WHERE o.request_id = ?';
        if (isset($q['position_id']) && ctype_digit((string) $q['position_id'])) {
            $sql .= ' AND o.position_id = ?';
            $params[] = (int) $q['position_id'];
        }
        $status = $q['status'] ?? 'submitted';
        if ($status !== 'all') {
            $sql .= ' AND o.status = ?';
            $params[] = in_array($status, ['submitted', 'withdrawn', 'rejected', 'expired', 'accepted'], true) ? $status : 'submitted';
        }
        $sql .= match ($q['sort'] ?? 'price') {
            'rating' => ' ORDER BY (m.rating_sum / GREATEST(m.rating_count, 1)) DESC, m.rating_count DESC',
            'experience' => ' ORDER BY m.years_experience DESC',
            'newest' => ' ORDER BY o.updated_at DESC',
            default => ' ORDER BY (o.price_minor + o.extras_minor) ASC',
        };
        $rows = $db->all($sql . ', o.id ASC LIMIT 100', $params);
        $feeBps = Settings::int('platform_fee_bps', 1000);
        $start = Clock::fromDb($r['starts_at']);
        $profiles = new ProfileService();
        return ['items' => array_map(function ($o) use ($feeBps, $db, $start, $r, $profiles) {
            $musician = $profiles->musicianProfile((int) $o['musician_id'], null);
            return $this->format($o) + [
                'breakdown' => Money::breakdown((int) $o['price_minor'], (int) $o['extras_minor'], $feeBps),
                'musician' => [
                    'user_id' => (int) $o['musician_id'], 'stage_name' => $o['stage_name'], 'city' => $o['city'],
                    'photo_url' => FileService::url($o['photo_file_id'] !== null ? (int) $o['photo_file_id'] : null),
                    'years_experience' => (int) $o['years_experience'], 'completed_bookings' => (int) $o['completed_count'],
                    'rating' => ProfileService::rating((int) $o['rating_sum'], (int) $o['rating_count']),
                    'verifications' => $musician['verifications'],
                    'instruments' => array_column($musician['instruments'], 'name'),
                ],
                'available' => !BookingService::hasConflict($db, (int) $o['musician_id'], $start, (int) $r['duration_minutes'], (int) $r['setup_minutes'], null),
            ];
        }, $rows)];
    }

    public function mine(int $musicianId, array $q): array
    {
        $status = $q['status'] ?? 'submitted';
        $params = [$musicianId];
        $sql = 'SELECT o.*, r.title, r.starts_at, r.city, r.timezone, i.name AS instrument FROM offers o JOIN requests r ON r.id = o.request_id
                JOIN request_positions p ON p.id = o.position_id JOIN instruments i ON i.id = p.instrument_id WHERE o.musician_id = ?';
        if ($status !== 'all' && in_array($status, ['submitted', 'withdrawn', 'rejected', 'expired', 'accepted'], true)) {
            $sql .= ' AND o.status = ?';
            $params[] = $status;
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $rows = Db::get()->all($sql . ' ORDER BY o.updated_at DESC, o.id DESC LIMIT 21 OFFSET ' . (($page - 1) * 20), $params);
        return [
            'items' => array_map(fn ($o) => $this->format($o) + [
                'request_title' => $o['title'], 'starts_at' => Clock::iso($o['starts_at']), 'city' => $o['city'],
                'timezone' => $o['timezone'], 'instrument' => $o['instrument'],
            ], array_slice($rows, 0, 20)),
            'page' => $page, 'has_more' => count($rows) > 20,
        ];
    }

    public function view(int $offerId, int $viewerId): array
    {
        $db = Db::get();
        $o = $db->one('SELECT o.*, r.client_id, r.title FROM offers o JOIN requests r ON r.id = o.request_id WHERE o.id = ?', [$offerId]);
        if ($o === null || ((int) $o['musician_id'] !== $viewerId && (int) $o['client_id'] !== $viewerId)) {
            throw ApiError::notFound('Offer');
        }
        $history = $db->all('SELECT kind, actor_id, price_minor, extras_minor, note, version, created_at FROM offer_revisions WHERE offer_id = ? ORDER BY id', [$offerId]);
        return $this->format($o) + [
            'request_title' => $o['title'],
            'breakdown' => Money::breakdown((int) $o['price_minor'], (int) $o['extras_minor'], Settings::int('platform_fee_bps', 1000)),
            'booking_id' => ($bid = $db->value('SELECT id FROM bookings WHERE offer_id = ?', [$offerId])) !== null ? (int) $bid : null,
            'history' => array_map(fn ($h) => [
                'kind' => $h['kind'], 'by' => (int) $h['actor_id'] === (int) $o['musician_id'] ? 'musician' : 'client',
                'price_minor' => (int) $h['price_minor'], 'extras_minor' => (int) $h['extras_minor'], 'note' => $h['note'],
                'version' => (int) $h['version'], 'at' => Clock::iso($h['created_at']),
            ], $history),
        ];
    }

    public static function revision(Db $db, int $offerId, int $actorId, string $kind, int $price, int $extras, ?string $note, int $version): void
    {
        $db->insert('offer_revisions', ['offer_id' => $offerId, 'actor_id' => $actorId, 'kind' => $kind, 'price_minor' => $price,
            'extras_minor' => $extras, 'note' => $note, 'version' => $version, 'created_at' => Clock::db()]);
    }

    private function format(array $o): array
    {
        return [
            'id' => (int) $o['id'], 'request_id' => (int) $o['request_id'], 'position_id' => (int) $o['position_id'],
            'musician_id' => (int) $o['musician_id'], 'price_minor' => (int) $o['price_minor'], 'extras_minor' => (int) $o['extras_minor'],
            'extras_note' => $o['extras_note'], 'total_minor' => (int) $o['price_minor'] + (int) $o['extras_minor'], 'currency' => $o['currency'],
            'included_services' => $o['included_services'], 'message' => $o['message'],
            'counter_price_minor' => $o['counter_price_minor'] !== null ? (int) $o['counter_price_minor'] : null, 'counter_note' => $o['counter_note'],
            'status' => $o['status'], 'version' => (int) $o['version'], 'updated_at' => Clock::iso($o['updated_at']),
        ];
    }

    private function lockOffer(Db $db, int $offerId): array
    {
        $o = $db->one('SELECT * FROM offers WHERE id = ? FOR UPDATE', [$offerId]);
        if ($o === null) {
            throw ApiError::notFound('Offer');
        }
        return $o;
    }

    private function assertClientOwns(Db $db, array $o, int $clientId): void
    {
        if ((int) $db->value('SELECT client_id FROM requests WHERE id = ?', [$o['request_id']]) !== $clientId) {
            throw ApiError::notFound('Offer');
        }
    }

    private function assertEditable(Db $db, array $o): void
    {
        if ($o['status'] !== 'submitted') {
            throw ApiError::conflict('offer_not_open', 'This offer can no longer be changed.');
        }
        $r = $db->one('SELECT status, offer_deadline FROM requests WHERE id = ?', [$o['request_id']]);
        if ($r['status'] !== 'open' || Clock::fromDb($r['offer_deadline']) <= Clock::now()) {
            throw ApiError::conflict('request_closed', 'This request is no longer accepting changes.');
        }
    }
}
