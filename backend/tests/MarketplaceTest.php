<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Db;
use Blissful\Support\Clock;

final class MarketplaceTest extends TestCase
{
    public function testRequestValidation(): void
    {
        $c = $this->user('c@example.test');
        $start = Clock::now()->modify('+5 days');
        [$s, $b] = $this->call('POST', '/api/requests', $this->requestPayload([
            'offer_deadline' => $start->modify('+1 day')->format('Y-m-d\TH:i:s\Z'),
            'starts_at' => $start->format('Y-m-d\TH:i:s\Z'),
        ]), $c['token']);
        $this->assertSame(422, $s);
        $this->assertArrayHasKey('offer_deadline', $b['error']['fields']);

        [$s, $b] = $this->call('POST', '/api/requests', $this->requestPayload(['starts_at' => Clock::now()->modify('-1 day')->format('Y-m-d\TH:i:s\Z')]), $c['token']);
        $this->assertArrayHasKey('starts_at', $b['error']['fields']);

        [$s, $b] = $this->call('POST', '/api/requests', $this->requestPayload(['starts_at' => '2027-01-10 10:00']), $c['token']);
        $this->assertArrayHasKey('starts_at', $b['error']['fields'], 'naive local times are rejected');

        [$s, $b] = $this->call('POST', '/api/requests', $this->requestPayload(['positions' => [
            ['instrument_id' => $this->instr['drums'], 'budget_minor' => 100],
            ['instrument_id' => $this->instr['drums'], 'budget_minor' => 100],
        ]]), $c['token']);
        $this->assertArrayHasKey('positions.1.instrument_id', $b['error']['fields']);

        [$s, $b] = $this->call('POST', '/api/requests', $this->requestPayload(['positions' => [['instrument_id' => $this->instr['drums'], 'budget_minor' => 99.5]]]), $c['token']);
        $this->assertArrayHasKey('positions.0.budget_minor', $b['error']['fields'], 'float money rejected');

        [$s, $b] = $this->call('POST', '/api/requests', $this->requestPayload(['currency' => 'EUR']), $c['token']);
        $this->assertArrayHasKey('currency', $b['error']['fields']);
    }

    public function testDraftPublishAndTimezoneStorage(): void
    {
        $c = $this->user('c@example.test');
        [$s, $r] = $this->call('POST', '/api/requests', $this->requestPayload(['publish' => false, 'starts_at' => '2027-03-14T09:30:00+02:00',
            'offer_deadline' => '2027-03-10T18:00:00+02:00']), $c['token']);
        $this->assertSame('draft', $r['status']);
        $this->assertSame('2027-03-14T07:30:00Z', $r['starts_at'], 'stored and returned in UTC');
        $this->assertSame('Africa/Harare', $r['timezone']);
        $this->assertSame('2027-03-14 07:30:00', Db::get()->value('SELECT starts_at FROM requests WHERE id = ?', [$r['id']]));

        $m = $this->musician('m@example.test');
        [$s, $feed] = $this->call('GET', '/api/requests/feed', null, $m['token']);
        $this->assertCount(0, $feed['items'], 'drafts are not visible');
        [$s] = $this->call('GET', "/api/requests/{$r['id']}", null, $m['token']);
        $this->assertSame(404, $s);

        [$s, $r] = $this->call('PATCH', "/api/requests/{$r['id']}", $this->requestPayload(['title' => 'Edited draft title', 'publish' => false]), $c['token']);
        $this->assertSame('Edited draft title', $r['title']);
        [$s, $r] = $this->call('POST', "/api/requests/{$r['id']}/publish", [], $c['token']);
        $this->assertSame('open', $r['status']);
        [$s, $feed] = $this->call('GET', '/api/requests/feed', null, $m['token']);
        $this->assertCount(1, $feed['items']);
    }

    public function testMatchingNotifiesOnlyRelevantMusicians(): void
    {
        $near = $this->musician('near@example.test', ['bass-guitar']);
        $wrongInstr = $this->musician('drummer@example.test', ['drums']);
        $far = $this->musician('far@example.test', ['bass-guitar'], ['city' => 'Bulawayo', 'lat' => -20.15, 'lng' => 28.58, 'travel_radius_km' => 50]);
        $paused = $this->musician('paused@example.test', ['bass-guitar'], ['accepting_bookings' => false]);
        $c = $this->user('client@example.test');
        $req = $this->openRequest($c['token']);
        $notified = array_map('intval', array_column(Db::get()->all("SELECT user_id FROM notifications WHERE type = 'request_match'"), 'user_id'));
        $this->assertSame([$near['id']], $notified);

        [$s, $feed] = $this->call('GET', '/api/requests/feed', null, $far['token']);
        $this->assertCount(0, $feed['items'], 'out of travel range');
        [$s, $feed] = $this->call('GET', '/api/requests/feed?anywhere=1', null, $far['token']);
        $this->assertCount(1, $feed['items']);
        $this->assertFalse($feed['items'][0]['in_travel_range']);
        [$s, $feed] = $this->call('GET', '/api/requests/feed', null, $wrongInstr['token']);
        $this->assertCount(0, $feed['items']);
        [$s, $feed] = $this->call('GET', '/api/requests/feed?instrument_id=' . $this->instr['bass-guitar'], null, $near['token']);
        $this->assertSame($req['id'], $feed['items'][0]['id']);
        $this->assertArrayNotHasKey('venue_address', $feed['items'][0]);
        // re-publishing / retries never duplicate notifications
        (new \Blissful\Service\RequestService())->notifyMatches($req['id']);
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM notifications WHERE type = 'request_match'"));
    }

    public function testUnavailableDaysSuppressMatching(): void
    {
        $free = $this->musician('free@example.test');
        $away = $this->musician('away@example.test');
        $day = Clock::now()->modify('+10 days')->setTime(0, 0);
        [$s, $b] = $this->call('POST', '/api/availability', ['starts_at' => $day->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $day->modify('+1 day')->format('Y-m-d\TH:i:s\Z')], $away['token']);
        $this->assertSame(200, $s, json_encode($b));
        $this->assertCount(1, $b['items']);
        [$s] = $this->call('POST', '/api/availability', ['starts_at' => $day->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $day->modify('-1 day')->format('Y-m-d\TH:i:s\Z')], $away['token']);
        $this->assertSame(422, $s, 'end must be after start');
        $c = $this->user('client@example.test');
        $this->openRequest($c['token']);
        $notified = array_map('intval', array_column(Db::get()->all("SELECT user_id FROM notifications WHERE type = 'request_match'"), 'user_id'));
        $this->assertSame([$free['id']], $notified);
        [$s, $b] = $this->call('DELETE', '/api/availability/' . $b['items'][0]['id'], null, $away['token']);
        $this->assertSame([], $b['items']);
    }

    public function testOfferRules(): void
    {
        $c = $this->user('client@example.test');
        $req = $this->openRequest($c['token']);
        // a client who also plays bass cannot bid on their own request
        $this->call('PUT', '/api/profile/musician', ['stage_name' => 'Self', 'city' => 'Harare', 'instrument_ids' => [$this->instr['bass-guitar']]], $c['token']);
        [$s, $b] = $this->call('POST', "/api/requests/{$req['id']}/offers", ['position_id' => $req['positions'][0]['id'], 'price_minor' => 100], $c['token']);
        $this->assertSame([403, 'forbidden'], [$s, $b['error']['code']]);

        $drummer = $this->musician('d@example.test', ['drums']);
        [$s] = $this->call('POST', "/api/requests/{$req['id']}/offers", ['position_id' => $req['positions'][0]['id'], 'price_minor' => 100], $drummer['token']);
        $this->assertSame(403, $s, 'instrument must be on the profile');

        $m = $this->musician('m@example.test');
        [$s, $b] = $this->call('POST', "/api/requests/{$req['id']}/offers", ['position_id' => $req['positions'][0]['id'], 'price_minor' => 100, 'extras_minor' => 500], $m['token']);
        $this->assertArrayHasKey('extras_note', $b['error']['fields']);

        [$s, $o] = $this->call('POST', "/api/requests/{$req['id']}/offers", ['position_id' => $req['positions'][0]['id'], 'accept_budget' => true], $m['token']);
        $this->assertSame(201, $s);
        $this->assertSame(10000, $o['price_minor'], 'accepting the proposed budget');
        $this->assertSame(11000, $o['breakdown']['total_minor'], '10% client fee');
        [$s, $b] = $this->call('POST', "/api/requests/{$req['id']}/offers", ['position_id' => $req['positions'][0]['id'], 'price_minor' => 100], $m['token']);
        $this->assertSame([409, 'already_offered'], [$s, $b['error']['code']]);
    }

    public function testEditWithdrawResubmitAndHistory(): void
    {
        $c = $this->user('client@example.test');
        $m = $this->musician('m@example.test');
        $req = $this->openRequest($c['token']);
        $o = $this->offer($m['token'], $req);
        [$s, $o2] = $this->call('PATCH', "/api/offers/{$o['id']}", ['price_minor' => 9500, 'message' => 'Updated'], $m['token']);
        $this->assertSame(2, $o2['version']);
        [$s, $o3] = $this->call('POST', "/api/offers/{$o['id']}/withdraw", [], $m['token']);
        $this->assertSame('withdrawn', $o3['status']);
        [$s] = $this->call('PATCH', "/api/offers/{$o['id']}", ['price_minor' => 9600], $m['token']);
        $this->assertSame(409, $s, 'withdrawn offers are not editable');
        $o4 = $this->offer($m['token'], $req, 0, ['price_minor' => 8800]);
        $this->assertSame($o['id'], $o4['id']);
        $this->assertSame('submitted', $o4['status']);
        $kinds = array_column($o4['history'], 'kind');
        $this->assertSame(['created', 'edited', 'withdrawn', 'created'], $kinds);
        $this->assertSame([9000, 9500, 9500, 8800], array_column($o4['history'], 'price_minor'));
        // other musicians cannot see or touch it
        $other = $this->musician('other@example.test');
        [$s] = $this->call('GET', "/api/offers/{$o['id']}", null, $other['token']);
        $this->assertSame(404, $s);
        [$s] = $this->call('POST', "/api/offers/{$o['id']}/withdraw", [], $other['token']);
        $this->assertSame(404, $s);
    }

    public function testCounterOfferRequiresExplicitAcceptance(): void
    {
        $c = $this->user('client@example.test');
        $m = $this->musician('m@example.test');
        $req = $this->openRequest($c['token']);
        $o = $this->offer($m['token'], $req, 0, ['price_minor' => 12000]);
        [$s, $co] = $this->call('POST', "/api/offers/{$o['id']}/counter", ['price_minor' => 10500, 'note' => 'Our budget is tight'], $c['token']);
        $this->assertSame(10500, $co['counter_price_minor']);
        $this->assertSame(12000, $co['price_minor'], 'terms unchanged until the musician agrees');
        [$s, $b] = $this->accept($c['token'], $co);
        $this->assertSame([409, 'counter_pending'], [$s, $b['error']['code']]);
        [$s, $ans] = $this->call('POST', "/api/offers/{$o['id']}/counter-response", ['accept' => true], $m['token']);
        $this->assertSame(10500, $ans['price_minor']);
        $this->assertSame(2, $ans['version']);
        // accepting with the stale version fails: the client must review the final terms
        [$s, $b] = $this->call('POST', "/api/offers/{$o['id']}/accept", ['expected_version' => 1], $c['token']);
        $this->assertSame([409, 'offer_changed'], [$s, $b['error']['code']]);
        [$s, $booking] = $this->call('POST', "/api/offers/{$o['id']}/accept", ['expected_version' => 2], $c['token']);
        $this->assertSame(200, $s);
        $this->assertSame(10500, $booking['price']['price_minor']);
        $this->assertSame(1050, $booking['price']['fee_minor']);
        $this->assertSame(11550, $booking['price']['total_minor']);
        $this->assertSame(2, $booking['terms']['offer_version']);
    }

    public function testAcceptCreatesBookingAndClosesPosition(): void
    {
        $c = $this->user('client@example.test');
        $m1 = $this->musician('m1@example.test');
        $m2 = $this->musician('m2@example.test');
        $req = $this->openRequest($c['token']);
        $o1 = $this->offer($m1['token'], $req);
        $o2 = $this->offer($m2['token'], $req);
        [$s, $offers] = $this->call('GET', "/api/requests/{$req['id']}/offers?sort=price", null, $c['token']);
        $this->assertCount(2, $offers['items']);
        $this->assertArrayHasKey('verifications', $offers['items'][0]['musician']);
        [$s, $booking] = $this->accept($c['token'], $o1);
        $this->assertSame('confirmed', $booking['status']);
        $this->assertSame('1 Secret Lane, Borrowdale', $booking['venue_address']);
        $this->assertSame('rejected', Db::get()->value('SELECT status FROM offers WHERE id = ?', [$o2['id']]));
        $this->assertSame('filled', Db::get()->value('SELECT status FROM requests WHERE id = ?', [$req['id']]));
        [$s, $b] = $this->accept($c['token'], $o2);
        $this->assertSame(409, $s);
        [$s, $b] = $this->accept($c['token'], $o1);
        $this->assertSame([409, 'offer_not_open'], [$s, $b['error']['code']], 'no duplicate acceptance');
        $this->assertSame(1, (int) Db::get()->value('SELECT COUNT(*) FROM bookings'));
        // booked musician now sees the private address; the rejected one does not
        [$s, $d1] = $this->call('GET', "/api/requests/{$req['id']}", null, $m1['token']);
        $this->assertSame('1 Secret Lane, Borrowdale', $d1['venue_address']);
        [$s, $d2] = $this->call('GET', "/api/requests/{$req['id']}", null, $m2['token']);
        $this->assertArrayNotHasKey('venue_address', $d2);
        $this->assertArrayNotHasKey('lat', $d2);
    }

    public function testMultiplePositionsAndCapacity(): void
    {
        $c = $this->user('client@example.test');
        $req = $this->openRequest($c['token'], ['positions' => [
            ['instrument_id' => $this->instr['bass-guitar'], 'quantity' => 1, 'budget_minor' => 8000],
            ['instrument_id' => $this->instr['vocals'], 'quantity' => 2, 'budget_minor' => 5000],
        ]]);
        $bass = $this->musician('bass@example.test', ['bass-guitar']);
        $v1 = $this->musician('v1@example.test', ['vocals']);
        $v2 = $this->musician('v2@example.test', ['vocals']);
        $v3 = $this->musician('v3@example.test', ['vocals']);
        $ov = [$this->offer($v1['token'], $req, 1), $this->offer($v2['token'], $req, 1), $this->offer($v3['token'], $req, 1)];
        $ob = $this->offer($bass['token'], $req, 0);
        $this->assertSame(200, $this->accept($c['token'], $ov[0])[0]);
        $this->assertSame('open', Db::get()->value('SELECT status FROM request_positions WHERE id = ?', [$req['positions'][1]['id']]));
        $this->assertSame('submitted', Db::get()->value('SELECT status FROM offers WHERE id = ?', [$ov[2]['id']]), 'one vocal place still open');
        $this->assertSame(200, $this->accept($c['token'], $ov[1])[0]);
        [$s, $b] = $this->accept($c['token'], $ov[2]);
        $this->assertSame(409, $s, 'cannot hire a third vocalist for two places');
        $this->assertSame('open', Db::get()->value('SELECT status FROM requests WHERE id = ?', [$req['id']]), 'bass still open');
        $this->assertSame(200, $this->accept($c['token'], $ob)[0]);
        $this->assertSame('filled', Db::get()->value('SELECT status FROM requests WHERE id = ?', [$req['id']]));
        $this->assertSame(3, (int) Db::get()->value('SELECT COUNT(*) FROM bookings'));
        $this->assertSame(2, (int) Db::get()->value('SELECT filled_count FROM request_positions WHERE id = ?', [$req['positions'][1]['id']]));
    }

    public function testMusicianCannotHoldTwoPlacesOnOneEvent(): void
    {
        $c = $this->user('client@example.test');
        $req = $this->openRequest($c['token'], ['positions' => [
            ['instrument_id' => $this->instr['keyboard'], 'quantity' => 1, 'budget_minor' => 8000],
            ['instrument_id' => $this->instr['piano'], 'quantity' => 1, 'budget_minor' => 8000],
        ]]);
        $m = $this->musician('keys@example.test', ['keyboard', 'piano']);
        $this->offer($m['token'], $req, 0);
        [$s, $b] = $this->call('POST', "/api/requests/{$req['id']}/offers", ['position_id' => $req['positions'][1]['id'], 'price_minor' => 1], $m['token']);
        $this->assertSame([409, 'already_offered'], [$s, $b['error']['code']]);
    }

    public function testOverlappingBookingsArePrevented(): void
    {
        $m = $this->musician('busy@example.test');
        $c1 = $this->user('c1@example.test');
        $c2 = $this->user('c2@example.test');
        $start = Clock::now()->modify('+10 days')->setTime(12, 0);
        $r1 = $this->openRequest($c1['token']);
        // second event starts 2h later: overlaps the first (3h long) once setup time is counted
        $r2 = $this->openRequest($c2['token'], ['starts_at' => $start->modify('+2 hours')->format('Y-m-d\TH:i:s\Z')]);
        $o1 = $this->offer($m['token'], $r1);
        $o2 = $this->offer($m['token'], $r2);
        $this->assertSame(200, $this->accept($c1['token'], $o1)[0]);
        [$s, $b] = $this->accept($c2['token'], $o2);
        $this->assertSame([409, 'schedule_conflict'], [$s, $b['error']['code']]);
        [$s, $offers] = $this->call('GET', "/api/requests/{$r2['id']}/offers", null, $c2['token']);
        $this->assertFalse($offers['items'][0]['available']);
        // a request that starts after the first ends (+setup) is fine
        $c3 = $this->user('c3@example.test');
        $r3 = $this->openRequest($c3['token'], ['starts_at' => $start->modify('+3 hours 30 minutes')->format('Y-m-d\TH:i:s\Z'), 'setup_minutes' => 30]);
        $o3 = $this->offer($m['token'], $r3);
        $this->assertSame(200, $this->accept($c3['token'], $o3)[0]);
        // and new offers into a busy slot are refused up front
        $c4 = $this->user('c4@example.test');
        $r4 = $this->openRequest($c4['token'], ['starts_at' => $start->modify('+1 hour')->format('Y-m-d\TH:i:s\Z')]);
        [$s, $b] = $this->call('POST', "/api/requests/{$r4['id']}/offers", ['position_id' => $r4['positions'][0]['id'], 'price_minor' => 1], $m['token']);
        $this->assertSame([409, 'schedule_conflict'], [$s, $b['error']['code']]);
    }

    public function testClientCancelsOpenRequest(): void
    {
        $c = $this->user('client@example.test');
        $m = $this->musician('m@example.test');
        $req = $this->openRequest($c['token']);
        $o = $this->offer($m['token'], $req);
        [$s, $r] = $this->call('POST', "/api/requests/{$req['id']}/cancel", [], $c['token']);
        $this->assertSame('cancelled', $r['status']);
        $this->assertSame('rejected', Db::get()->value('SELECT status FROM offers WHERE id = ?', [$o['id']]));
        [$s] = $this->call('POST', "/api/requests/{$req['id']}/offers", ['position_id' => $req['positions'][0]['id'], 'price_minor' => 1], $m['token']);
        $this->assertSame(409, $s);
    }

    public function testExpiryJob(): void
    {
        $c = $this->user('client@example.test');
        $m = $this->musician('m@example.test');
        $req = $this->openRequest($c['token']);
        $o = $this->offer($m['token'], $req);
        // Client extends the deadline by a day, then lets it lapse.
        $newDeadline = Clock::fromDb(Db::get()->value('SELECT offer_deadline FROM requests WHERE id = ?', [$req['id']]))->modify('+1 day');
        [$s] = $this->call('PATCH', "/api/requests/{$req['id']}", ['offer_deadline' => $newDeadline->format('Y-m-d\TH:i:s\Z')], $c['token']);
        $this->assertSame(200, $s);
        $this->travel('+8 days 1 hour'); // past the original deadline, before the extended one
        [$s] = $this->call('POST', "/api/requests/{$req['id']}/offers", ['position_id' => $req['positions'][0]['id'], 'price_minor' => 1],
            $this->musician('late@example.test')['token']);
        $this->assertSame(201, $s, 'extended deadline still accepts offers');
        $this->travel('+1 day');
        [$s, $b] = $this->accept($this->relogin($c), $o);
        $this->assertSame([409, 'request_closed'], [$s, $b['error']['code']]);
        $this->assertSame(1, \Blissful\Service\Jobs::expireRequests());
        $this->assertSame('expired', Db::get()->value('SELECT status FROM requests WHERE id = ?', [$req['id']]));
        $this->assertSame('expired', Db::get()->value('SELECT status FROM offers WHERE id = ?', [$o['id']]));
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM notifications WHERE type = 'request_expired'"));
        $this->assertSame(0, \Blissful\Service\Jobs::expireRequests(), 'idempotent');
    }
}
