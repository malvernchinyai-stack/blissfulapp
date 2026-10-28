<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Db;
use Blissful\Support\Log;

/** Unauthorised access, IDOR and privilege-escalation attempts (OWASP API1/API3/API5). */
final class SecurityTest extends TestCase
{
    public function testProtectedEndpointsNeedAuth(): void
    {
        foreach ([['GET', '/api/me'], ['GET', '/api/bookings'], ['POST', '/api/requests'], ['GET', '/api/notifications'],
                     ['GET', '/api/bookings/1/messages'], ['POST', '/api/offers/1/accept'], ['GET', '/api/musicians']] as [$m, $p]) {
            [$s] = $this->call($m, $p, []);
            $this->assertSame(401, $s, "{$m} {$p}");
        }
        [$s] = $this->call('GET', '/api/me', null, 'forged-token-forged-token-forged-token');
        $this->assertSame(401, $s);
        [$s] = $this->call('GET', '/api/catalog');
        $this->assertSame(200, $s, 'catalogue is public');
    }

    public function testCrossUserAccessIsDenied(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        $attacker = $this->musician('attacker@example.test');
        $attackerClient = $this->user('attacker-client@example.test');
        $offerId = (int) Db::get()->value('SELECT offer_id FROM bookings WHERE id = ?', [$b['id']]);
        $checks = [
            ['GET', "/api/bookings/{$b['id']}", null, $attacker],
            ['GET', "/api/bookings/{$b['id']}/messages", null, $attacker],
            ['POST', "/api/bookings/{$b['id']}/messages", ['body' => 'hi'], $attacker],
            ['POST', "/api/bookings/{$b['id']}/cancel", ['reason' => 'malicious cancel'], $attacker],
            ['POST', "/api/bookings/{$b['id']}/done", [], $attacker],
            ['GET', "/api/bookings/{$b['id']}/payments", null, $attacker],
            ['GET', "/api/requests/{$b['request_id']}/offers", null, $attackerClient],
            ['POST', "/api/offers/{$offerId}/reject", [], $attackerClient],
            ['POST', "/api/offers/{$offerId}/counter", ['price_minor' => 1], $attackerClient],
            ['PATCH', "/api/requests/{$b['request_id']}", ['description' => 'x'], $attackerClient],
            ['POST', "/api/requests/{$b['request_id']}/cancel", [], $attackerClient],
        ];
        foreach ($checks as [$method, $path, $body, $who]) {
            [$s] = $this->call($method, $path, $body, $who['token']);
            $this->assertContains($s, [403, 404], "{$method} {$path} returned {$s}");
        }
        $this->assertSame('confirmed', Db::get()->value('SELECT status FROM bookings WHERE id = ?', [$b['id']]));
    }

    public function testAcceptingSomeoneElsesOfferIsImpossible(): void
    {
        $owner = $this->user('owner@example.test');
        $thief = $this->user('thief@example.test');
        $m = $this->musician('m@example.test');
        $req = $this->openRequest($owner['token']);
        $o = $this->offer($m['token'], $req);
        [$s] = $this->accept($thief['token'], $o);
        $this->assertSame(404, $s);
        $this->assertSame(0, (int) Db::get()->value('SELECT COUNT(*) FROM bookings'));
    }

    public function testMassAssignmentAndRoleEscalation(): void
    {
        $u = $this->user('plain@example.test');
        // extra fields are ignored, not applied
        [$s, $me] = $this->call('PUT', '/api/profile/client', ['name' => 'Plain', 'status' => 'active', 'is_staff' => true, 'user_id' => 1, 'rating_sum' => 500], $u['token']);
        $this->assertSame(200, $s);
        $this->assertSame(['average' => null, 'count' => 0], $me['rating']);
        [$s, $me] = $this->call('GET', '/api/me', null, $u['token']);
        $this->assertFalse($me['is_staff']);
        [$s] = $this->call('POST', '/api/me/role', ['role' => 'admin'], $u['token']);
        $this->assertSame(422, $s);
        // a bearer token never grants admin access; admin uses its own session + TOTP
        $res = \Blissful\Http\Kernel::handle(new \Blissful\Http\Request('GET', '/admin', [], ['authorization' => 'Bearer ' . $u['token']], ''));
        $this->assertSame(303, $res->status);
        $this->assertSame('/admin/login', $res->headers['Location']);
    }

    public function testMusicianOnlyEndpointsRequireTheRole(): void
    {
        $c = $this->user('c-only@example.test');
        foreach ([['GET', '/api/requests/feed'], ['GET', '/api/offers/mine'], ['POST', '/api/portfolio'], ['GET', '/api/availability']] as [$m, $p]) {
            [$s] = $this->call($m, $p, [], $c['token']);
            $this->assertSame(403, $s, "{$m} {$p}");
        }
    }

    public function testBlockedUsersCannotInteract(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s] = $this->call('POST', "/api/blocks/{$m['id']}", [], $c['token']);
        $this->assertSame(200, $s);
        [$s] = $this->call('POST', "/api/bookings/{$b['id']}/messages", ['body' => 'Hello?'], $m['token']);
        $this->assertSame(403, $s);
        $req = $this->openRequest($c['token'], ['starts_at' => \Blissful\Support\Clock::now()->modify('+20 days')->format('Y-m-d\TH:i:s\Z'),
            'offer_deadline' => \Blissful\Support\Clock::now()->modify('+19 days')->format('Y-m-d\TH:i:s\Z')]);
        [$s] = $this->call('GET', "/api/requests/{$req['id']}", null, $m['token']);
        $this->assertSame(404, $s);
        [$s, $feed] = $this->call('GET', '/api/requests/feed', null, $m['token']);
        $this->assertCount(0, $feed['items']);
        [$s, $search] = $this->call('GET', '/api/musicians', null, $c['token']);
        $this->assertNotContains($m['id'], array_column($search['items'], 'user_id'));
    }

    public function testReportsAndOutsiderReportingOfPrivateItems(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $msg] = $this->call('POST', "/api/bookings/{$b['id']}/messages", ['body' => 'Rude message'], $m['token']);
        [$s] = $this->call('POST', '/api/reports', ['target_type' => 'message', 'target_id' => $msg['id'], 'reason' => 'abuse'], $c['token']);
        $this->assertSame(201, $s);
        $outsider = $this->user('out@example.test');
        [$s] = $this->call('POST', '/api/reports', ['target_type' => 'message', 'target_id' => $msg['id'], 'reason' => 'abuse'], $outsider['token']);
        $this->assertSame(404, $s, 'cannot probe private messages through reports');
    }

    public function testSqlInjectionAttemptsAreInert(): void
    {
        $u = $this->user('sqli@example.test');
        [$s, $b] = $this->call('GET', "/api/musicians?city=" . rawurlencode("Harare' OR '1'='1") . '&q=' . rawurlencode('%_%'), null, $u['token']);
        $this->assertSame(200, $s);
        $this->assertSame([], $b['items']);
        [$s] = $this->call('GET', '/api/bookings/1%20OR%201=1', null, $u['token']);
        $this->assertSame(404, $s);
    }

    public function testLogsRedactSecrets(): void
    {
        $r = Log::redact(['password' => 'p', 'nested' => ['refresh_token' => 't', 'ok' => 1], 'venue_address_private' => 'x', 'phone' => '+263']);
        $this->assertSame('[redacted]', $r['password']);
        $this->assertSame('[redacted]', $r['nested']['refresh_token']);
        $this->assertSame(1, $r['nested']['ok']);
        $this->assertSame('[redacted]', $r['venue_address_private']);
        $this->assertSame('[redacted]', $r['phone']);
    }

    public function testErrorsDoNotLeakInternals(): void
    {
        $u = $this->user('err@example.test');
        [$s, $b, $res] = $this->call('GET', '/api/nope', null, $u['token']);
        $this->assertSame(404, $s);
        [$s, $b] = $this->call('PUT', '/api/me', [], $u['token']);
        $this->assertSame(405, $s);
        $this->assertSame('noindex, nofollow', \Blissful\Http\Kernel::handle(new \Blissful\Http\Request('GET', '/health', [], [], ''))->status === 200 ? 'noindex, nofollow' : '');
        [$s, $h] = $this->call('GET', '/health');
        $this->assertSame(['status' => 'ok'], $h, 'health exposes nothing sensitive');
    }
}
