<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Db;
use Blissful\Http\Kernel;
use Blissful\Http\Request;
use Blissful\Support\Clock;
use Blissful\Support\Mailer;
use Blissful\Support\Settings;
use PHPUnit\Framework\TestCase as Base;

abstract class TestCase extends Base
{
    private const KEEP = ['instruments', 'genres', 'settings', 'schema_migrations'];
    protected static int $ipCounter = 1;
    private static ?array $settingsSnapshot = null;
    protected array $instr = [];

    protected function setUp(): void
    {
        Clock::freeze(null);
        $db = Db::get();
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->all('SHOW TABLES') as $row) {
            $t = array_values($row)[0];
            if (!in_array($t, self::KEEP, true)) {
                $db->pdo()->exec("TRUNCATE TABLE `{$t}`");
            }
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        self::$settingsSnapshot ??= $db->all('SELECT name, value_json FROM settings');
        foreach (self::$settingsSnapshot as $row) {
            $db->exec('UPDATE settings SET value_json = ? WHERE name = ?', [$row['value_json'], $row['name']]);
        }
        $db->exec('UPDATE instruments SET is_active = 1');
        Settings::flush();
        Mailer::$sent = [];
        \Blissful\Service\PaymentService::useProvider(null);
        $this->instr = array_map('intval', array_column($db->all('SELECT id, slug FROM instruments'), 'id', 'slug'));
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
    }

    /** Calls the real HTTP kernel in-process. Returns [status, decoded JSON body, Response]. */
    protected function call(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = [], ?string $ip = null): array
    {
        $h = array_change_key_case($headers);
        if ($token !== null) {
            $h['authorization'] = 'Bearer ' . $token;
        }
        $h['content-type'] = 'application/json';
        [$p, $qs] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($qs, $query);
        $req = new Request($method, $p, $query, $h, $body === null ? '' : json_encode($body), [], [], $ip ?? '10.0.0.' . (self::$ipCounter++ % 250 + 1));
        $res = Kernel::handle($req);
        return [$res->status, json_decode($res->body, true), $res];
    }

    protected function lastCode(): string
    {
        $m = end(Mailer::$sent);
        preg_match('/\b(\d{6})\b/', $m['text'], $mm);
        return $mm[1];
    }

    /** Registers + verifies an account and returns ['token','refresh','id','email']. */
    protected function user(string $email, bool $musician = false, string $password = 'Correct-horse-1'): array
    {
        [$s] = $this->call('POST', '/api/auth/register', ['email' => $email, 'password' => $password, 'display_name' => 'User ' . strtok($email, '@'),
            'as_musician' => $musician, 'accept_terms' => true]);
        $this->assertSame(202, $s);
        [$s, $b] = $this->call('POST', '/api/auth/verify-email', ['email' => $email, 'code' => $this->lastCode()]);
        $this->assertSame(200, $s, json_encode($b));
        return ['token' => $b['access_token'], 'refresh' => $b['refresh_token'], 'id' => $b['user']['id'], 'email' => $email];
    }

    protected function musician(string $email, array $instrumentSlugs = ['bass-guitar'], array $extra = []): array
    {
        $u = $this->user($email, true);
        [$s, $b] = $this->call('PUT', '/api/profile/musician', $extra + [
            'stage_name' => 'Stage ' . strtok($email, '@'), 'city' => 'Harare', 'lat' => -17.8292, 'lng' => 31.0522, 'travel_radius_km' => 50,
            'years_experience' => 5, 'typical_rate_minor' => 5000, 'currency' => 'USD',
            'instrument_ids' => array_map(fn ($s) => $this->instr[$s], $instrumentSlugs),
        ], $u['token']);
        $this->assertSame(200, $s, json_encode($b));
        return $u;
    }

    protected function requestPayload(array $overrides = []): array
    {
        $start = Clock::now()->modify('+10 days')->setTime(12, 0);
        return $overrides + [
            'title' => 'Wedding reception band', 'event_type' => 'wedding', 'description' => 'Evening reception',
            'starts_at' => $start->format('Y-m-d\TH:i:s\Z'), 'timezone' => 'Africa/Harare', 'duration_minutes' => 180, 'setup_minutes' => 30,
            'city' => 'Harare', 'area' => 'Borrowdale', 'lat' => -17.80, 'lng' => 31.05, 'venue_name' => 'Garden Venue',
            'venue_address_private' => '1 Secret Lane, Borrowdale', 'currency' => 'USD',
            'offer_deadline' => $start->modify('-2 days')->format('Y-m-d\TH:i:s\Z'),
            'positions' => [['instrument_id' => $this->instr['bass-guitar'], 'quantity' => 1, 'budget_minor' => 10000]],
            'publish' => true,
        ];
    }

    protected function openRequest(string $clientToken, array $overrides = []): array
    {
        [$s, $b] = $this->call('POST', '/api/requests', $this->requestPayload($overrides), $clientToken);
        $this->assertSame(201, $s, json_encode($b));
        return $b;
    }

    protected function offer(string $musicianToken, array $request, int $positionIndex = 0, array $body = []): array
    {
        [$s, $b] = $this->call('POST', "/api/requests/{$request['id']}/offers", $body + ['position_id' => $request['positions'][$positionIndex]['id'], 'price_minor' => 9000],
            $musicianToken);
        $this->assertSame(201, $s, json_encode($b));
        return $b;
    }

    protected function accept(string $clientToken, array $offer): array
    {
        return $this->call('POST', "/api/offers/{$offer['id']}/accept", ['expected_version' => $offer['version']], $clientToken);
    }

    /** Creates a confirmed booking end to end and returns [client, musician, booking]. */
    protected function confirmedBooking(array $requestOverrides = []): array
    {
        $client = $this->user('client' . uniqid() . '@example.test');
        $musician = $this->musician('muso' . uniqid() . '@example.test');
        $req = $this->openRequest($client['token'], $requestOverrides);
        $offer = $this->offer($musician['token'], $req);
        [$s, $booking] = $this->accept($client['token'], $offer);
        $this->assertSame(200, $s, json_encode($booking));
        return [$client, $musician, $booking];
    }

    protected function relogin(array $u): string
    {
        [$s, $b] = $this->call('POST', '/api/auth/login', ['email' => $u['email'], 'password' => 'Correct-horse-1']);
        $this->assertSame(200, $s, json_encode($b));
        return $b['access_token'];
    }

    protected function travel(string $modify): void
    {
        Clock::freeze(Clock::now()->modify($modify));
    }
}
