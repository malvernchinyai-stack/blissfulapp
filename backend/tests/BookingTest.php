<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Db;
use Blissful\Service\Jobs;
use Blissful\Support\Clock;

final class BookingTest extends TestCase
{
    public function testListsAndDetailVisibility(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s] = $this->call('PUT', '/api/profile/client', ['name' => 'Client Org', 'org_type' => 'church', 'phone' => '+263 77 123 4567'], $c['token']);
        $this->assertSame(200, $s);
        [$s, $list] = $this->call('GET', '/api/bookings?role=client&scope=upcoming', null, $c['token']);
        $this->assertSame($b['id'], $list['items'][0]['id']);
        [$s, $list] = $this->call('GET', '/api/bookings?role=musician', null, $m['token']);
        $this->assertSame($b['id'], $list['items'][0]['id']);
        [$s, $d] = $this->call('GET', "/api/bookings/{$b['id']}", null, $m['token']);
        $this->assertSame('musician', $d['my_role']);
        $this->assertSame('+263771234567', $d['client']['phone'], 'contact details shared once booked');
        [$s, $pub] = $this->call('GET', "/api/clients/{$c['id']}", null, $m['token']);
        $this->assertArrayNotHasKey('phone', $pub, 'never on public profiles');
        $outsider = $this->user('nosy@example.test');
        [$s] = $this->call('GET', "/api/bookings/{$b['id']}", null, $outsider['token']);
        $this->assertSame(404, $s);
    }

    /** @dataProvider cancellationTiers */
    public function testClientCancellationPolicy(string $travel, int $expectedChargeBps): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        $this->travel($travel);
        $token = $this->relogin($c);
        [$s, $d] = $this->call('GET', "/api/bookings/{$b['id']}", null, $token);
        $this->assertSame($expectedChargeBps, $d['cancellation_preview']['charge_bps']);
        [$s, $d] = $this->call('POST', "/api/bookings/{$b['id']}/cancel", ['reason' => 'Event postponed by family'], $token);
        $this->assertSame(200, $s, json_encode($d));
        $this->assertSame('cancelled', $d['status']);
        $total = $b['price']['total_minor'];
        $this->assertSame(intdiv($total * $expectedChargeBps + 5000, 10000), $d['cancellation_charge_minor']);
    }

    public static function cancellationTiers(): array
    {
        // booking starts +10 days at 12:00 UTC
        return [
            'more than 72h before' => ['+1 day', 0],
            'between 24h and 72h' => ['+8 days', 5000],
            'under 24h' => ['+9 days 13 hours', 10000],
        ];
    }

    public function testMusicianCancellationReopensPlaceAndIsFreeForClient(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $d] = $this->call('POST', "/api/bookings/{$b['id']}/cancel", ['reason' => 'Family emergency'], $m['token']);
        $this->assertSame(0, $d['cancellation_charge_minor']);
        $this->assertSame('open', Db::get()->value('SELECT status FROM requests WHERE id = ?', [$b['request_id']]));
        $this->assertSame(0, (int) Db::get()->value('SELECT filled_count FROM request_positions WHERE request_id = ?', [$b['request_id']]));
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'booking_cancelled'", [$c['id']]));
        [$s] = $this->call('POST', "/api/bookings/{$b['id']}/cancel", ['reason' => 'again please'], $m['token']);
        $this->assertSame(409, $s);
        // another musician can now be hired
        $m2 = $this->musician('replacement@example.test');
        $req = ['id' => $b['request_id'], 'positions' => [['id' => (int) Db::get()->value('SELECT id FROM request_positions WHERE request_id = ?', [$b['request_id']])]]];
        $o = $this->offer($m2['token'], $req);
        $this->assertSame(200, $this->accept($c['token'], $o)[0]);
    }

    public function testCancellationUsesPolicyAgreedAtBooking(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        Db::get()->exec("UPDATE settings SET value_json = ? WHERE name = 'cancellation_policy'",
            [json_encode(['tiers' => [['min_hours_before' => 0, 'charge_bps' => 10000]], 'musician_cancel_charge_bps' => 0])]);
        \Blissful\Support\Settings::flush();
        [$s, $d] = $this->call('GET', "/api/bookings/{$b['id']}", null, $c['token']);
        $this->assertSame(0, $d['cancellation_preview']['charge_bps'], 'later policy edits do not apply retroactively');
    }

    public function testRescheduleNeedsTheOtherParty(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        $newStart = Clock::now()->modify('+12 days')->setTime(15, 0)->format('Y-m-d\TH:i:s\Z');
        [$s, $d] = $this->call('POST', "/api/bookings/{$b['id']}/reschedule", ['new_starts_at' => $newStart, 'note' => 'Venue change'], $c['token']);
        $pid = $d['pending_reschedule']['id'];
        $this->assertSame($b['starts_at'], $d['starts_at'], 'unchanged until accepted');
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/reschedule/{$pid}", ['action' => 'accept'], $c['token']);
        $this->assertSame(403, $s, 'proposer cannot accept their own proposal');
        [$s, $d] = $this->call('POST', "/api/bookings/{$b['id']}/reschedule/{$pid}", ['action' => 'accept'], $m['token']);
        $this->assertSame($newStart, $d['starts_at']);
        $this->assertSame(Clock::now()->modify('+12 days')->setTime(18, 0)->format('Y-m-d\TH:i:s\Z'), $d['ends_at'], 'duration preserved');
        [$s] = $this->call('POST', "/api/bookings/{$b['id']}/reschedule/{$pid}", ['action' => 'accept'], $m['token']);
        $this->assertSame(409, $s);
    }

    public function testRescheduleIntoConflictIsRefused(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        $c2 = $this->user('other-client@example.test');
        $r2 = $this->openRequest($c2['token'], ['starts_at' => Clock::now()->modify('+15 days')->setTime(12, 0)->format('Y-m-d\TH:i:s\Z')]);
        $o2 = $this->offer($m['token'], $r2);
        $this->assertSame(200, $this->accept($c2['token'], $o2)[0]);
        [$s, $d] = $this->call('POST', "/api/bookings/{$b['id']}/reschedule",
            ['new_starts_at' => Clock::now()->modify('+15 days')->setTime(13, 0)->format('Y-m-d\TH:i:s\Z')], $c['token']);
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/reschedule/{$d['pending_reschedule']['id']}", ['action' => 'accept'], $m['token']);
        $this->assertSame([409, 'schedule_conflict'], [$s, $x['error']['code']]);
    }

    public function testCompletionReviewsAndDisputeWindow(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/done", [], $c['token']);
        $this->assertSame([409, 'not_started'], [$s, $x['error']['code']]);
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/review", ['rating' => 5], $c['token']);
        $this->assertSame(409, $s, 'no reviews before completion');

        $this->travel('+10 days 4 hours');
        $ct = $this->relogin($c);
        $mt = $this->relogin($m);
        $this->assertSame(1, Jobs::startBookings());
        [$s, $d] = $this->call('POST', "/api/bookings/{$b['id']}/done", [], $ct);
        $this->assertSame('in_progress', $d['status']);
        [$s, $d] = $this->call('POST', "/api/bookings/{$b['id']}/done", [], $mt);
        $this->assertSame('completed', $d['status']);
        $this->assertTrue($d['can']['dispute']);
        $this->assertTrue($d['can']['review']);

        [$s] = $this->call('POST', "/api/bookings/{$b['id']}/review", ['rating' => 6], $ct);
        $this->assertSame(422, $s);
        [$s] = $this->call('POST', "/api/bookings/{$b['id']}/review", ['rating' => 5, 'comment' => 'Brilliant'], $ct);
        $this->assertSame(201, $s);
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/review", ['rating' => 1], $ct);
        $this->assertSame([409, 'already_reviewed'], [$s, $x['error']['code']]);
        [$s] = $this->call('POST', "/api/bookings/{$b['id']}/review", ['rating' => 4], $mt);
        $this->assertSame(201, $s, 'musician reviews the client');
        $outsider = $this->user('outsider@example.test');
        [$s] = $this->call('POST', "/api/bookings/{$b['id']}/review", ['rating' => 1], $outsider['token']);
        $this->assertSame(404, $s);
        [$s, $prof] = $this->call('GET', "/api/musicians/{$m['id']}", null, $ct);
        $this->assertSame(['average' => '5.0', 'count' => 1], $prof['rating']);
        $this->assertSame(1, $prof['completed_bookings']);

        $this->travel('+49 hours');
        $ct = $this->relogin($c);
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/dispute", ['reason' => 'quality', 'details' => 'Too late to complain now'], $ct);
        $this->assertSame([409, 'dispute_not_allowed'], [$s, $x['error']['code']]);
    }

    public function testDisputeFreezesBookingAndStaffResolve(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        $this->travel('+10 days 1 hour');
        $ct = $this->relogin($c);
        [$s, $d] = $this->call('POST', "/api/bookings/{$b['id']}/dispute", ['reason' => 'no_show', 'details' => 'The musician did not arrive at the venue.'], $ct);
        $this->assertSame('disputed', $d['status']);
        $this->assertFalse($d['can']['mark_done']);
        [$s] = $this->call('POST', "/api/bookings/{$b['id']}/dispute", ['reason' => 'no_show', 'details' => 'Opening a second one'], $ct);
        $this->assertSame(409, $s);
        $this->travel('+5 days');
        Jobs::autoComplete();
        $this->assertSame('disputed', Db::get()->value('SELECT status FROM bookings WHERE id = ?', [$b['id']]), 'auto-complete skips disputes');
        $did = (int) Db::get()->value('SELECT id FROM disputes WHERE booking_id = ?', [$b['id']]);
        \Blissful\Service\SafetyService::resolveDispute(999, $did, 'cancel', 'Musician did not attend. Full refund.', $b['price']['total_minor']);
        $this->assertSame('cancelled', Db::get()->value('SELECT status FROM bookings WHERE id = ?', [$b['id']]));
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'dispute.resolved'"));
    }

    public function testAutoCompleteAndReminders(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        $this->travel('+9 days 13 hours');
        $this->assertSame(1, Jobs::sendReminders());
        $this->assertSame(0, Jobs::sendReminders(), 'reminders are sent once');
        $this->assertSame(2, (int) Db::get()->value("SELECT COUNT(*) FROM notifications WHERE type = 'reminder'"));
        $this->travel('+3 days');
        Jobs::startBookings();
        $this->assertSame(1, Jobs::autoComplete());
        $this->assertSame('completed', Db::get()->value('SELECT status FROM bookings WHERE id = ?', [$b['id']]));
    }
}
