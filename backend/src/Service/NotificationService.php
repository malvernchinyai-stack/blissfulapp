<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Support\Clock;
use Blissful\Support\Validator;

/**
 * In-app notification history + transactional outbox for push. `dedupe_key` makes every notification
 * idempotent: retried jobs or requests can never notify the same user twice for the same event.
 */
final class NotificationService
{
    public const CATEGORIES = ['requests', 'offers', 'bookings', 'messages', 'reminders', 'disputes', 'account'];

    private const TYPE_CATEGORY = [
        'request_match' => 'requests', 'offer_received' => 'offers', 'offer_updated' => 'offers', 'offer_withdrawn' => 'offers',
        'offer_accepted' => 'bookings', 'offer_rejected' => 'offers', 'counter_proposed' => 'offers', 'counter_answered' => 'offers',
        'booking_confirmed' => 'bookings', 'booking_cancelled' => 'bookings', 'booking_started' => 'bookings',
        'booking_completed' => 'bookings', 'reschedule_proposed' => 'bookings', 'reschedule_answered' => 'bookings',
        'message' => 'messages', 'reminder' => 'reminders', 'dispute_opened' => 'disputes', 'dispute_resolved' => 'disputes',
        'payment' => 'bookings', 'review_received' => 'bookings', 'request_expired' => 'requests', 'offer_expired' => 'offers',
        'verification' => 'account',
    ];

    public static function send(int $userId, string $type, string $title, string $body, array $data, string $dedupeKey): ?int
    {
        $db = Db::get();
        $now = Clock::db();
        $affected = $db->exec(
            'INSERT IGNORE INTO notifications (user_id, type, title, body, data_json, dedupe_key, created_at) VALUES (?,?,?,?,?,?,?)',
            [$userId, $type, mb_substr($title, 0, 150), mb_substr($body, 0, 500), json_encode($data), mb_substr($dedupeKey, 0, 150), $now]
        );
        if ($affected === 0) {
            return null; // duplicate — already notified
        }
        $id = (int) $db->pdo()->lastInsertId();
        $category = self::TYPE_CATEGORY[$type] ?? 'account';
        $pref = $db->one('SELECT push_enabled FROM notification_prefs WHERE user_id = ? AND category = ?', [$userId, $category]);
        $pushOn = $pref === null ? true : (bool) $pref['push_enabled'];
        $hasDevice = (bool) $db->value('SELECT 1 FROM device_tokens WHERE user_id = ? LIMIT 1', [$userId]);
        $db->insert('notification_outbox', [
            'notification_id' => $id, 'channel' => 'push',
            'status' => $pushOn && $hasDevice ? 'pending' : 'skipped',
            'next_attempt_at' => $now, 'updated_at' => $now,
            'last_error' => $pushOn ? ($hasDevice ? null : 'no_device') : 'disabled_by_user',
        ]);
        return $id;
    }

    public function list(int $userId, ?int $beforeId, int $limit): array
    {
        $params = [$userId];
        $sql = 'SELECT * FROM notifications WHERE user_id = ?';
        if ($beforeId !== null) {
            $sql .= ' AND id < ?';
            $params[] = $beforeId;
        }
        $rows = Db::get()->all($sql . ' ORDER BY id DESC LIMIT ' . ($limit + 1), $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => array_map(fn ($r) => [
                'id' => (int) $r['id'], 'type' => $r['type'], 'title' => $r['title'], 'body' => $r['body'],
                'data' => json_decode((string) $r['data_json'], true) ?: (object) [],
                'read' => $r['read_at'] !== null, 'created_at' => Clock::iso($r['created_at']),
            ], $rows),
            'next_before_id' => $more ? (int) end($rows)['id'] : null,
            'unread_count' => (int) Db::get()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]),
        ];
    }

    public function markRead(int $userId, array $input): array
    {
        $v = new Validator($input);
        $ids = $v->intList('ids', 200);
        $all = $v->bool('all');
        $v->done();
        if ($all) {
            Db::get()->exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Clock::db(), $userId]);
        } elseif ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            Db::get()->exec("UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND id IN ($in)",
                array_merge([Clock::db(), $userId], $ids));
        }
        return ['status' => 'ok'];
    }

    public function prefs(int $userId): array
    {
        $rows = Db::get()->all('SELECT category, push_enabled FROM notification_prefs WHERE user_id = ?', [$userId]);
        $map = array_column($rows, 'push_enabled', 'category');
        return array_map(fn ($c) => ['category' => $c, 'push_enabled' => isset($map[$c]) ? (bool) $map[$c] : true], self::CATEGORIES);
    }

    public function updatePrefs(int $userId, array $input): array
    {
        $items = $input['prefs'] ?? null;
        if (!is_array($items) || count($items) > count(self::CATEGORIES)) {
            throw \Blissful\Http\ApiError::validation(['prefs' => 'Send a list of {category, push_enabled}.']);
        }
        foreach ($items as $item) {
            $v = new Validator(is_array($item) ? $item : []);
            $cat = $v->enum('category', self::CATEGORIES);
            $on = $v->bool('push_enabled', true);
            $v->done();
            Db::get()->exec('INSERT INTO notification_prefs (user_id, category, push_enabled) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE push_enabled = VALUES(push_enabled)', [$userId, $cat, $on ? 1 : 0]);
        }
        return $this->prefs($userId);
    }

    public function registerDevice(int $userId, array $input): array
    {
        $v = new Validator($input);
        $token = $v->string('token', 20, 400);
        $v->done();
        Db::get()->exec('INSERT INTO device_tokens (user_id, token, platform, updated_at) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), updated_at = VALUES(updated_at)',
            [$userId, $token, 'android', Clock::db()]);
        return ['status' => 'registered'];
    }

    public function unregisterDevice(int $userId, array $input): array
    {
        $token = is_string($input['token'] ?? null) ? $input['token'] : '';
        Db::get()->exec('DELETE FROM device_tokens WHERE user_id = ? AND token = ?', [$userId, $token]);
        return ['status' => 'removed'];
    }
}
