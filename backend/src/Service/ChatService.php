<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Support\Clock;
use Blissful\Support\RateLimiter;
use Blissful\Support\Validator;

/** Booking chat between the two participants only. Polled by the app (shared hosting has no WebSockets). */
final class ChatService
{
    public function list(int $userId, int $bookingId, array $q): array
    {
        $b = (new BookingService())->participantBooking($bookingId, $userId);
        $after = isset($q['after']) && ctype_digit((string) $q['after']) ? (int) $q['after'] : null;
        $before = isset($q['before']) && ctype_digit((string) $q['before']) ? (int) $q['before'] : null;
        $limit = 50;
        $db = Db::get();
        if ($after !== null) {
            $rows = $db->all("SELECT * FROM messages WHERE booking_id = ? AND id > ? ORDER BY id ASC LIMIT {$limit}", [$bookingId, $after]);
        } else {
            $sql = 'SELECT * FROM messages WHERE booking_id = ?' . ($before !== null ? ' AND id < ?' : '') . " ORDER BY id DESC LIMIT {$limit}";
            $rows = array_reverse($db->all($sql, $before !== null ? [$bookingId, $before] : [$bookingId]));
        }
        if ($rows !== []) {
            $last = (int) end($rows)['id'];
            $db->exec('INSERT INTO message_reads (booking_id, user_id, last_read_id) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))', [$bookingId, $userId, $last]);
        }
        return [
            'items' => array_map(fn ($m) => [
                'id' => (int) $m['id'], 'mine' => (int) $m['sender_id'] === $userId,
                'body' => $m['is_hidden'] ? null : $m['body'], 'hidden' => (bool) $m['is_hidden'], 'created_at' => Clock::iso($m['created_at']),
            ], $rows),
            'chat_open' => (new BookingService())->chatOpen($b),
        ];
    }

    public function send(int $userId, int $bookingId, array $input): array
    {
        $v = new Validator($input);
        $body = $v->string('body', 1, 2000);
        $v->done();
        $bs = new BookingService();
        $b = $bs->participantBooking($bookingId, $userId);
        if (!$bs->chatOpen($b)) {
            throw ApiError::conflict('chat_closed', 'This conversation is closed.');
        }
        $other = (int) $b['client_id'] === $userId ? (int) $b['musician_id'] : (int) $b['client_id'];
        $db = Db::get();
        if ($db->value('SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)', [$userId, $other, $other, $userId])) {
            throw ApiError::forbidden('Messaging is not available between these accounts.');
        }
        RateLimiter::enforce("chat:min:{$userId}", 20, 60, 'You are sending messages too quickly.');
        RateLimiter::enforce("chat:day:{$userId}", 400, 86400, 'Daily message limit reached.');
        $dupe = $db->value('SELECT 1 FROM messages WHERE booking_id = ? AND sender_id = ? AND body = ? AND created_at > ?',
            [$bookingId, $userId, $body, Clock::db(Clock::now()->modify('-30 seconds'))]);
        if ($dupe) {
            throw ApiError::conflict('duplicate_message', 'You just sent that message.');
        }
        $id = $db->insert('messages', ['booking_id' => $bookingId, 'sender_id' => $userId, 'body' => $body, 'created_at' => Clock::db()]);
        $name = (int) $b['client_id'] === $userId
            ? (string) $db->value('SELECT name FROM client_profiles WHERE user_id = ?', [$userId])
            : (string) $db->value('SELECT stage_name FROM musician_profiles WHERE user_id = ?', [$userId]);
        // One push per sender per booking per 2-minute window keeps busy chats from flooding the phone.
        $bucket = intdiv(Clock::now()->getTimestamp(), 120);
        NotificationService::send($other, 'message', 'Message from ' . $name, mb_substr($body, 0, 120), ['booking_id' => $bookingId],
            "msg:{$bookingId}:{$userId}:{$bucket}");
        return ['id' => $id, 'mine' => true, 'body' => $body, 'hidden' => false, 'created_at' => Clock::iso(Clock::db())];
    }

    public function unreadCounts(int $userId): array
    {
        $rows = Db::get()->all("SELECT b.id, COUNT(m.id) AS unread FROM bookings b
            JOIN messages m ON m.booking_id = b.id AND m.sender_id <> ?
            LEFT JOIN message_reads r ON r.booking_id = b.id AND r.user_id = ?
            WHERE (b.client_id = ? OR b.musician_id = ?) AND m.id > COALESCE(r.last_read_id, 0)
            GROUP BY b.id", [$userId, $userId, $userId, $userId]);
        return array_map(fn ($r) => ['booking_id' => (int) $r['id'], 'unread' => (int) $r['unread']], $rows);
    }
}
