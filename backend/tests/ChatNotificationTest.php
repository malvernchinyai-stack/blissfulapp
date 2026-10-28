<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Db;
use Blissful\Service\Jobs;
use Blissful\Service\NotificationService;
use Blissful\Service\PushSender;

final class ChatNotificationTest extends TestCase
{
    public function testChatBetweenParticipants(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $msg] = $this->call('POST', "/api/bookings/{$b['id']}/messages", ['body' => 'Hi! What time is soundcheck?'], $c['token']);
        $this->assertSame(201, $s);
        [$s, $unread] = $this->call('GET', '/api/messages/unread', null, $m['token']);
        $this->assertSame([['booking_id' => $b['id'], 'unread' => 1]], $unread['items']);
        [$s, $list] = $this->call('GET', "/api/bookings/{$b['id']}/messages", null, $m['token']);
        $this->assertFalse($list['items'][0]['mine']);
        $this->assertSame('Hi! What time is soundcheck?', $list['items'][0]['body']);
        [$s, $unread] = $this->call('GET', '/api/messages/unread', null, $m['token']);
        $this->assertSame([], $unread['items']);
        [$s, $poll] = $this->call('GET', "/api/bookings/{$b['id']}/messages?after={$msg['id']}", null, $m['token']);
        $this->assertSame([], $poll['items'], 'incremental polling returns only new messages');
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/messages", ['body' => 'Hi! What time is soundcheck?'], $c['token']);
        $this->assertSame([409, 'duplicate_message'], [$s, $x['error']['code']]);
        [$s] = $this->call('POST', "/api/bookings/{$b['id']}/messages", ['body' => str_repeat('x', 2001)], $c['token']);
        $this->assertSame(422, $s);
    }

    public function testChatSpamIsRateLimited(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        $statuses = [];
        for ($i = 0; $i < 22; $i++) {
            [$s] = $this->call('POST', "/api/bookings/{$b['id']}/messages", ['body' => "message {$i}"], $c['token']);
            $statuses[] = $s;
        }
        $this->assertSame(20, count(array_filter($statuses, fn ($s) => $s === 201)));
        $this->assertContains(429, $statuses);
        $this->assertLessThanOrEqual(1, (int) Db::get()->value("SELECT COUNT(*) FROM notifications WHERE type = 'message' AND user_id = ?", [$m['id']]),
            'a burst produces one push, not twenty');
    }

    public function testNotificationDedupeHistoryAndPrefs(): void
    {
        $u = $this->user('n@example.test');
        $this->assertNotNull(NotificationService::send($u['id'], 'offer_received', 'T', 'B', [], 'k1'));
        $this->assertNull(NotificationService::send($u['id'], 'offer_received', 'T', 'B', [], 'k1'));
        [$s, $list] = $this->call('GET', '/api/notifications', null, $u['token']);
        $this->assertSame(1, $list['unread_count']);
        $this->call('POST', '/api/notifications/read', ['all' => true], $u['token']);
        [$s, $list] = $this->call('GET', '/api/notifications', null, $u['token']);
        $this->assertSame(0, $list['unread_count']);
        [$s, $prefs] = $this->call('PUT', '/api/notifications/prefs', ['prefs' => [['category' => 'offers', 'push_enabled' => false]]], $u['token']);
        $this->assertFalse(array_column($prefs['items'], 'push_enabled', 'category')['offers']);
        $this->call('POST', '/api/devices', ['token' => str_repeat('d', 40)], $u['token']);
        NotificationService::send($u['id'], 'offer_received', 'T', 'B', [], 'k2');
        NotificationService::send($u['id'], 'booking_confirmed', 'T', 'B', [], 'k3');
        $statuses = Db::get()->all('SELECT n.dedupe_key, o.status, o.last_error FROM notification_outbox o JOIN notifications n ON n.id = o.notification_id ORDER BY o.id');
        $this->assertSame('disabled_by_user', $statuses[1]['last_error']);
        $this->assertSame('pending', $statuses[2]['status']);
        $this->assertSame(3, count($list['items']) + 2);
    }

    public function testPushDeliveryRetriesAndDropsDeadTokens(): void
    {
        $u = $this->user('p@example.test');
        $this->call('POST', '/api/devices', ['token' => 'good-token-' . str_repeat('a', 30)], $u['token']);
        $this->call('POST', '/api/devices', ['token' => 'dead-token-' . str_repeat('b', 30)], $u['token']);
        $fake = new class extends PushSender {
            public array $calls = [];
            public bool $fail = true;
            public function configured(): bool { return true; }
            public function send(string $t, string $title, string $body, array $data): string
            {
                $this->calls[] = $t;
                if (str_starts_with($t, 'dead')) { return 'invalid_token'; }
                return $this->fail ? 'retry' : 'sent';
            }
        };
        Jobs::$push = $fake;
        NotificationService::send($u['id'], 'reminder', 'T', 'B', ['booking_id' => 1], 'r1');
        Jobs::deliverNotifications();
        $this->assertSame(0, (int) Db::get()->value('SELECT COUNT(*) FROM device_tokens WHERE token LIKE ?', ['dead%']));
        $this->assertSame('pending', Db::get()->value('SELECT status FROM notification_outbox'));
        Jobs::deliverNotifications();
        $this->assertCount(2, $fake->calls, 'not retried before the backoff time');
        $this->travel('+3 minutes');
        $fake->fail = false;
        Jobs::deliverNotifications();
        $this->assertSame('sent', Db::get()->value('SELECT status FROM notification_outbox'));
        Jobs::deliverNotifications();
        $this->assertCount(3, $fake->calls, 'never sent twice');
        Jobs::$push = null;
    }

    public function testPushNotConfiguredFallsBackToInAppOnly(): void
    {
        $u = $this->user('nopush@example.test');
        $this->call('POST', '/api/devices', ['token' => str_repeat('z', 40)], $u['token']);
        NotificationService::send($u['id'], 'reminder', 'T', 'B', [], 'x');
        Jobs::$push = null;
        Jobs::deliverNotifications();
        $this->assertSame(['skipped', 'push_not_configured'], array_values(Db::get()->one('SELECT status, last_error FROM notification_outbox')));
        [$s, $list] = $this->call('GET', '/api/notifications', null, $u['token']);
        $this->assertCount(1, $list['items']);
    }
}
