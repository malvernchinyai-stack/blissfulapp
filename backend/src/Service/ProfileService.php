<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Support\Clock;
use Blissful\Support\Validator;

final class ProfileService
{
    /** What each badge actually means — shown verbatim in the app. Only approved checks are ever returned. */
    public const VERIFICATION_LABELS = [
        'email' => ['label' => 'Email confirmed', 'explains' => 'The account holder entered a code sent to their email address.'],
        'identity' => ['label' => 'ID checked', 'explains' => 'Blissful Studios staff compared a government ID document with the profile name. This does not check skills or conduct.'],
        'portfolio' => ['label' => 'Samples reviewed', 'explains' => 'Blissful Studios staff listened to or watched the performance samples on this profile.'],
    ];

    public const ORG_TYPES = ['individual', 'church', 'event_planner', 'venue', 'business', 'organization'];

    public function catalog(): array
    {
        $db = Db::get();
        return [
            'instruments' => array_map(fn ($r) => ['id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name']],
                $db->all('SELECT id, slug, name FROM instruments WHERE is_active = 1 ORDER BY sort_order, name')),
            'genres' => array_map(fn ($r) => ['id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name']],
                $db->all('SELECT id, slug, name FROM genres WHERE is_active = 1 ORDER BY name')),
            'org_types' => self::ORG_TYPES,
            'event_types' => RequestService::EVENT_TYPES,
            'currencies' => \Blissful\Support\Settings::get('supported_currencies', ['USD']),
            'platform_fee_bps' => \Blissful\Support\Settings::int('platform_fee_bps', 1000),
            'cancellation_policy' => \Blissful\Support\Settings::get('cancellation_policy'),
            'dispute_window_hours' => \Blissful\Support\Settings::int('dispute_window_hours', 48),
        ];
    }

    public function myProfiles(int $userId): array
    {
        return [
            'client' => $this->clientProfile($userId, true),
            'musician' => Db::get()->value('SELECT 1 FROM musician_profiles WHERE user_id = ?', [$userId]) ? $this->musicianProfile($userId, $userId) : null,
        ];
    }

    public function updateClient(int $userId, array $input): array
    {
        $v = new Validator($input);
        $name = $v->string('name', 2, 150);
        $org = $v->enum('org_type', self::ORG_TYPES, false, 'individual');
        $city = $v->string('city', 0, 80, false);
        $area = $v->string('area', 0, 80, false);
        $bio = $v->string('bio', 0, 1000, false);
        $photo = $v->int('photo_file_id', 1, PHP_INT_MAX, false);
        $phone = $v->phone('phone');
        $v->done();
        if ($photo !== null) {
            $this->assertOwnFile($userId, $photo, 'avatar');
        }
        $db = Db::get();
        $db->exec('INSERT INTO client_profiles (user_id, name, org_type, city, area, bio, photo_file_id, updated_at) VALUES (?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE name=VALUES(name), org_type=VALUES(org_type), city=VALUES(city), area=VALUES(area),
                   bio=VALUES(bio), photo_file_id=VALUES(photo_file_id), updated_at=VALUES(updated_at)',
            [$userId, $name, $org, $city, $area, $bio, $photo, Clock::db()]);
        if ($v->has('phone')) {
            $db->exec('UPDATE users SET phone = ? WHERE id = ?', [$phone, $userId]);
        }
        return $this->clientProfile($userId, true);
    }

    public function upsertMusician(int $userId, array $input): array
    {
        $v = new Validator($input);
        $stage = $v->string('stage_name', 2, 120);
        $bio = $v->string('bio', 0, 2000, false);
        $city = $v->string('city', 2, 80);
        $area = $v->string('area', 0, 80, false);
        $lat = $v->decimal('lat', -90, 90);
        $lng = $v->decimal('lng', -180, 180);
        $radius = $v->int('travel_radius_km', 0, 1000, false, 30);
        $years = $v->int('years_experience', 0, 80, false, 0);
        $rate = $v->money('typical_rate_minor', false);
        $currency = $v->currency('currency');
        $eqProvided = $v->string('equipment_provided', 0, 1000, false);
        $eqRequired = $v->string('equipment_required', 0, 1000, false);
        $accepting = $v->bool('accepting_bookings', true);
        $photo = $v->int('photo_file_id', 1, PHP_INT_MAX, false);
        $instruments = $v->intList('instrument_ids', 10, true);
        $genres = $v->intList('genre_ids', 12);
        if ($v->has('instrument_ids') && $instruments === []) {
            $v->addError('instrument_ids', 'Choose at least one instrument or service.');
        }
        $v->done();
        $db = Db::get();
        $this->assertIdsExist('instruments', $instruments, 'instrument_ids');
        $this->assertIdsExist('genres', $genres, 'genre_ids');
        if ($photo !== null) {
            $this->assertOwnFile($userId, $photo, 'avatar');
        }
        $db->tx(function (Db $db) use ($userId, $stage, $bio, $city, $area, $lat, $lng, $radius, $years, $rate, $currency, $eqProvided, $eqRequired, $accepting, $photo, $instruments, $genres) {
            $now = Clock::db();
            $db->exec('INSERT INTO musician_profiles (user_id, stage_name, bio, city, area, lat, lng, travel_radius_km, years_experience,
                       typical_rate_minor, currency, equipment_provided, equipment_required, accepting_bookings, photo_file_id, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE stage_name=VALUES(stage_name), bio=VALUES(bio), city=VALUES(city), area=VALUES(area),
                       lat=VALUES(lat), lng=VALUES(lng), travel_radius_km=VALUES(travel_radius_km), years_experience=VALUES(years_experience),
                       typical_rate_minor=VALUES(typical_rate_minor), currency=VALUES(currency), equipment_provided=VALUES(equipment_provided),
                       equipment_required=VALUES(equipment_required), accepting_bookings=VALUES(accepting_bookings),
                       photo_file_id=VALUES(photo_file_id), updated_at=VALUES(updated_at)',
                [$userId, $stage, $bio, $city, $area, $lat, $lng, $radius, $years, $rate ?? 0, $currency, $eqProvided, $eqRequired,
                    $accepting ? 1 : 0, $photo, $now]);
            $db->exec('DELETE FROM musician_instruments WHERE musician_id = ?', [$userId]);
            foreach ($instruments as $iid) {
                $db->insert('musician_instruments', ['musician_id' => $userId, 'instrument_id' => $iid]);
            }
            $db->exec('DELETE FROM musician_genres WHERE musician_id = ?', [$userId]);
            foreach ($genres as $gid) {
                $db->insert('musician_genres', ['musician_id' => $userId, 'genre_id' => $gid]);
            }
            $db->exec('UPDATE users SET is_musician = 1, updated_at = ? WHERE id = ?', [$now, $userId]);
        });
        return $this->musicianProfile($userId, $userId);
    }

    public function musicianProfile(int $musicianId, ?int $viewerId): array
    {
        $db = Db::get();
        $p = $db->one("SELECT m.*, u.status, u.email_verified_at FROM musician_profiles m JOIN users u ON u.id = m.user_id
                       WHERE m.user_id = ? AND u.status = 'active'", [$musicianId]);
        if ($p === null) {
            throw ApiError::notFound('Musician');
        }
        $instruments = $db->all('SELECT i.id, i.name FROM musician_instruments mi JOIN instruments i ON i.id = mi.instrument_id WHERE mi.musician_id = ? ORDER BY i.sort_order', [$musicianId]);
        $genres = $db->all('SELECT g.id, g.name FROM musician_genres mg JOIN genres g ON g.id = mg.genre_id WHERE mg.musician_id = ? ORDER BY g.name', [$musicianId]);
        $portfolio = $db->all('SELECT * FROM portfolio_items WHERE musician_id = ? ORDER BY id DESC', [$musicianId]);
        $isSelf = $viewerId === $musicianId;
        $out = [
            'user_id' => (int) $p['user_id'],
            'stage_name' => $p['stage_name'],
            'bio' => $p['bio'],
            'city' => $p['city'],
            'area' => $p['area'],
            'travel_radius_km' => (int) $p['travel_radius_km'],
            'years_experience' => (int) $p['years_experience'],
            'typical_rate_minor' => (int) $p['typical_rate_minor'],
            'currency' => $p['currency'],
            'equipment_provided' => $p['equipment_provided'],
            'equipment_required' => $p['equipment_required'],
            'accepting_bookings' => (bool) $p['accepting_bookings'],
            'photo_url' => FileService::url($p['photo_file_id'] !== null ? (int) $p['photo_file_id'] : null),
            'instruments' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $instruments),
            'genres' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $genres),
            'portfolio' => array_map(fn ($r) => [
                'id' => (int) $r['id'], 'kind' => $r['kind'], 'title' => $r['title'],
                'url' => $r['kind'] === 'video_link' ? $r['url'] : FileService::url((int) $r['file_id']),
            ], $portfolio),
            'rating' => self::rating((int) $p['rating_sum'], (int) $p['rating_count']),
            'completed_bookings' => (int) $p['completed_count'],
            'verifications' => $this->approvedVerifications($musicianId, $p['email_verified_at'] !== null),
            'recent_reviews' => $this->reviewsFor($musicianId, 'musician', 5),
        ];
        if ($isSelf) {
            $out['lat'] = $p['lat'] !== null ? (float) $p['lat'] : null;
            $out['lng'] = $p['lng'] !== null ? (float) $p['lng'] : null;
            $out['pending_verifications'] = array_column($db->all("SELECT kind FROM verifications WHERE user_id = ? AND status = 'pending'", [$musicianId]), 'kind');
        }
        return $out;
    }

    public function clientProfile(int $clientId, bool $isSelf = false): array
    {
        $db = Db::get();
        $p = $db->one("SELECT c.*, u.email_verified_at, u.status, u.phone FROM client_profiles c JOIN users u ON u.id = c.user_id WHERE c.user_id = ?", [$clientId]);
        if ($p === null || ($p['status'] !== 'active' && !$isSelf)) {
            throw ApiError::notFound('Client');
        }
        $out = [
            'user_id' => (int) $p['user_id'],
            'name' => $p['name'],
            'org_type' => $p['org_type'],
            'city' => $p['city'],
            'area' => $p['area'],
            'bio' => $p['bio'],
            'photo_url' => FileService::url($p['photo_file_id'] !== null ? (int) $p['photo_file_id'] : null),
            'rating' => self::rating((int) $p['rating_sum'], (int) $p['rating_count']),
            'completed_bookings' => (int) $db->value("SELECT COUNT(*) FROM bookings WHERE client_id = ? AND status = 'completed'", [$clientId]),
            'verifications' => $this->approvedVerifications($clientId, $p['email_verified_at'] !== null),
            'recent_reviews' => $this->reviewsFor($clientId, 'client', 5),
        ];
        if ($isSelf) {
            $out['phone'] = $p['phone'];
        }
        return $out;
    }

    public function search(array $q, ?int $viewerId): array
    {
        $where = ["u.status = 'active'", 'm.accepting_bookings = 1'];
        $params = [];
        if (isset($q['instrument_id']) && ctype_digit((string) $q['instrument_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM musician_instruments mi WHERE mi.musician_id = m.user_id AND mi.instrument_id = ?)';
            $params[] = (int) $q['instrument_id'];
        }
        if (isset($q['genre_id']) && ctype_digit((string) $q['genre_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM musician_genres mg WHERE mg.musician_id = m.user_id AND mg.genre_id = ?)';
            $params[] = (int) $q['genre_id'];
        }
        if (isset($q['city']) && is_string($q['city']) && trim($q['city']) !== '') {
            $where[] = 'm.city = ?';
            $params[] = mb_substr(trim($q['city']), 0, 80);
        }
        if (isset($q['q']) && is_string($q['q']) && mb_strlen(trim($q['q'])) >= 2) {
            $where[] = 'm.stage_name LIKE ?';
            $params[] = '%' . addcslashes(mb_substr(trim($q['q']), 0, 60), '%_\\') . '%';
        }
        if (isset($q['min_rating']) && ctype_digit((string) $q['min_rating'])) {
            $where[] = 'm.rating_count > 0 AND m.rating_sum >= ? * m.rating_count';
            $params[] = min(5, (int) $q['min_rating']);
        }
        if ($viewerId !== null) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = m.user_id) OR (b.blocker_id = m.user_id AND b.blocked_id = ?))';
            $params[] = $viewerId;
            $params[] = $viewerId;
        }
        $sort = match ($q['sort'] ?? 'rating') {
            'experience' => 'm.years_experience DESC',
            'rate_low' => 'm.typical_rate_minor ASC',
            default => '(m.rating_sum / GREATEST(m.rating_count,1)) DESC, m.completed_count DESC',
        };
        $page = max(1, min(500, (int) ($q['page'] ?? 1)));
        $per = 20;
        $sql = 'SELECT m.*, u.email_verified_at FROM musician_profiles m JOIN users u ON u.id = m.user_id WHERE ' . implode(' AND ', $where)
            . " ORDER BY {$sort}, m.user_id ASC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per);
        $rows = Db::get()->all($sql, $params);
        $more = count($rows) > $per;
        $rows = array_slice($rows, 0, $per);
        $ids = array_map(fn ($r) => (int) $r['user_id'], $rows);
        $instr = [];
        if ($ids !== []) {
            $in = implode(',', $ids);
            foreach (Db::get()->all("SELECT mi.musician_id, i.name FROM musician_instruments mi JOIN instruments i ON i.id = mi.instrument_id WHERE mi.musician_id IN ($in) ORDER BY i.sort_order") as $r) {
                $instr[(int) $r['musician_id']][] = $r['name'];
            }
        }
        return [
            'items' => array_map(fn ($r) => [
                'user_id' => (int) $r['user_id'], 'stage_name' => $r['stage_name'], 'city' => $r['city'], 'area' => $r['area'],
                'photo_url' => FileService::url($r['photo_file_id'] !== null ? (int) $r['photo_file_id'] : null),
                'instruments' => $instr[(int) $r['user_id']] ?? [], 'years_experience' => (int) $r['years_experience'],
                'typical_rate_minor' => (int) $r['typical_rate_minor'], 'currency' => $r['currency'],
                'rating' => self::rating((int) $r['rating_sum'], (int) $r['rating_count']),
                'completed_bookings' => (int) $r['completed_count'],
            ], $rows),
            'page' => $page,
            'has_more' => $more,
        ];
    }

    public function addPortfolio(int $userId, array $input): array
    {
        $this->requireMusicianProfile($userId);
        $v = new Validator($input);
        $kind = $v->enum('kind', ['photo', 'audio', 'video_link']);
        $title = $v->string('title', 0, 120, false);
        $fileId = null;
        $url = null;
        if ($kind === 'video_link') {
            $url = $v->url('url', true);
        } else {
            $fileId = $v->int('file_id', 1, PHP_INT_MAX);
        }
        $v->done();
        if ($fileId !== null) {
            $f = $this->assertOwnFile($userId, $fileId, 'portfolio');
            $isAudio = str_starts_with($f['mime'], 'audio/');
            if (($kind === 'audio') !== $isAudio) {
                throw ApiError::validation(['file_id' => 'File type does not match the item kind.']);
            }
        }
        if ((int) Db::get()->value('SELECT COUNT(*) FROM portfolio_items WHERE musician_id = ?', [$userId]) >= 20) {
            throw ApiError::conflict('portfolio_full', 'You can have up to 20 portfolio items.');
        }
        Db::get()->insert('portfolio_items', ['musician_id' => $userId, 'kind' => $kind, 'file_id' => $fileId, 'url' => $url, 'title' => $title, 'created_at' => Clock::db()]);
        return $this->musicianProfile($userId, $userId);
    }

    public function deletePortfolio(int $userId, int $itemId): array
    {
        $item = Db::get()->one('SELECT * FROM portfolio_items WHERE id = ? AND musician_id = ?', [$itemId, $userId]);
        if ($item === null) {
            throw ApiError::notFound('Portfolio item');
        }
        Db::get()->exec('DELETE FROM portfolio_items WHERE id = ?', [$itemId]);
        if ($item['file_id'] !== null) {
            (new FileService())->softDelete($userId, (int) $item['file_id']);
        }
        return $this->musicianProfile($userId, $userId);
    }

    public function availability(int $userId): array
    {
        $rows = Db::get()->all('SELECT * FROM availability_blocks WHERE musician_id = ? AND ends_at > ? ORDER BY starts_at', [$userId, Clock::db()]);
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'starts_at' => Clock::iso($r['starts_at']), 'ends_at' => Clock::iso($r['ends_at']), 'note' => $r['note']], $rows);
    }

    public function addUnavailable(int $userId, array $input): array
    {
        $this->requireMusicianProfile($userId);
        $v = new Validator($input);
        $start = $v->datetime('starts_at');
        $end = $v->datetime('ends_at');
        $note = $v->string('note', 0, 120, false);
        $v->done();
        if ($end <= $start || $end->getTimestamp() - $start->getTimestamp() > 90 * 86400) {
            throw ApiError::validation(['ends_at' => 'End must be after start and within 90 days.']);
        }
        Db::get()->insert('availability_blocks', ['musician_id' => $userId, 'starts_at' => Clock::db($start), 'ends_at' => Clock::db($end), 'note' => $note]);
        return $this->availability($userId);
    }

    public function deleteUnavailable(int $userId, int $id): array
    {
        Db::get()->exec('DELETE FROM availability_blocks WHERE id = ? AND musician_id = ?', [$id, $userId]);
        return $this->availability($userId);
    }

    public function requestVerification(int $userId, array $input): array
    {
        $v = new Validator($input);
        $kind = $v->enum('kind', ['identity', 'portfolio']);
        $fileId = $kind === 'identity' ? $v->int('document_file_id', 1, PHP_INT_MAX) : null;
        $v->done();
        if ($fileId !== null) {
            $this->assertOwnFile($userId, $fileId, 'verification');
        }
        if ($kind === 'portfolio' && !(int) Db::get()->value('SELECT COUNT(*) FROM portfolio_items WHERE musician_id = ?', [$userId])) {
            throw ApiError::conflict('no_portfolio', 'Add at least one performance sample first.');
        }
        if (Db::get()->value("SELECT 1 FROM verifications WHERE user_id = ? AND kind = ? AND status IN ('pending','approved')", [$userId, $kind])) {
            throw ApiError::conflict('verification_exists', 'This check is already pending or approved.');
        }
        Db::get()->insert('verifications', ['user_id' => $userId, 'kind' => $kind, 'document_file_id' => $fileId, 'created_at' => Clock::db()]);
        return ['status' => 'pending'];
    }

    public function reviewsFor(int $userId, string $role, int $limit = 20, int $page = 1): array
    {
        $off = (max(1, $page) - 1) * $limit;
        $rows = Db::get()->all("SELECT r.rating, r.comment, r.created_at, u.display_name AS author FROM reviews r JOIN users u ON u.id = r.author_id
                                WHERE r.subject_id = ? AND r.subject_role = ? AND r.is_hidden = 0 ORDER BY r.id DESC LIMIT {$limit} OFFSET {$off}", [$userId, $role]);
        return array_map(fn ($r) => ['rating' => (int) $r['rating'], 'comment' => $r['comment'], 'author' => $r['author'], 'created_at' => Clock::iso($r['created_at'])], $rows);
    }

    public static function rating(int $sum, int $count): array
    {
        // Average as a string with one decimal, computed with integers (no float drift in display).
        $avgTenths = $count > 0 ? intdiv($sum * 10 + intdiv($count, 2), $count) : 0;
        return ['average' => $count > 0 ? sprintf('%d.%d', intdiv($avgTenths, 10), $avgTenths % 10) : null, 'count' => $count];
    }

    private function approvedVerifications(int $userId, bool $emailVerified): array
    {
        $out = [];
        if ($emailVerified) {
            $out[] = ['kind' => 'email'] + self::VERIFICATION_LABELS['email'];
        }
        foreach (Db::get()->all("SELECT DISTINCT kind FROM verifications WHERE user_id = ? AND status = 'approved'", [$userId]) as $r) {
            if (isset(self::VERIFICATION_LABELS[$r['kind']])) {
                $out[] = ['kind' => $r['kind']] + self::VERIFICATION_LABELS[$r['kind']];
            }
        }
        return $out;
    }

    private function requireMusicianProfile(int $userId): void
    {
        if (!Db::get()->value('SELECT 1 FROM musician_profiles WHERE user_id = ?', [$userId])) {
            throw ApiError::conflict('musician_profile_required', 'Create your musician profile first.');
        }
    }

    private function assertOwnFile(int $userId, int $fileId, string $purpose): array
    {
        $f = Db::get()->one('SELECT * FROM files WHERE id = ? AND owner_id = ? AND purpose = ? AND deleted_at IS NULL', [$fileId, $userId, $purpose]);
        if ($f === null) {
            throw ApiError::validation(['file_id' => 'File not found.']);
        }
        return $f;
    }

    private function assertIdsExist(string $table, array $ids, string $field): void
    {
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        $n = (int) Db::get()->value("SELECT COUNT(*) FROM {$table} WHERE is_active = 1 AND id IN ($in)");
        if ($n !== count($ids)) {
            throw ApiError::validation([$field => 'Contains an unknown or inactive item.']);
        }
    }
}
