<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Db;
use Blissful\Http\Kernel;
use Blissful\Http\Request;
use Blissful\Payment\PaynowProvider;
use Blissful\Payment\SandboxProvider;
use Blissful\Service\PaymentService;
use Blissful\Support\Clock;

final class PaymentTest extends TestCase
{
    private function webhook(array $fields): int
    {
        $raw = http_build_query($fields);
        return Kernel::handle(new Request('POST', '/webhooks/sandbox', [], ['content-type' => 'application/x-www-form-urlencoded'], $raw, $fields))->status;
    }

    private function signed(array $payment, string $status, ?int $amount = null, string $ts = '1'): array
    {
        $f = ['reference' => $payment['reference'], 'status' => $status, 'amount_minor' => (string) ($amount ?? $payment['amount_minor']),
            'provider_ref' => 'SBX-1', 'ts' => $ts];
        $f['signature'] = SandboxProvider::sign($f);
        return $f;
    }

    public function testSandboxPaymentAndDuplicateWebhooks(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $m['token']);
        $this->assertSame(403, $s, 'only the client pays');
        [$s, $p] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $this->assertSame(201, $s);
        $this->assertTrue($p['is_test_payment']);
        $this->assertSame($b['price']['total_minor'], $p['amount_minor']);
        $this->assertStringContainsString('/sandbox/pay/', $p['checkout_url']);
        $this->assertSame('pending', $p['status']);
        [$s, $again] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $this->assertSame($p['id'], $again['id'], 'reuses the pending attempt instead of creating duplicates');

        $hook = $this->signed($p, 'paid');
        $this->assertSame(200, $this->webhook($hook));
        $this->assertSame(200, $this->webhook($hook), 'duplicate delivery is acknowledged');
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM notifications WHERE type = 'payment' AND user_id = ?", [$c['id']]));
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM booking_events WHERE event = 'payment_received'"));
        // a later 'failed' callback cannot downgrade a paid payment
        $this->assertSame(200, $this->webhook($this->signed($p, 'failed', null, '2')));
        [$s, $list] = $this->call('GET', "/api/bookings/{$b['id']}/payments", null, $c['token']);
        $this->assertSame('paid', $list['items'][0]['status']);
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $this->assertSame([409, 'already_paid'], [$s, $x['error']['code']]);
        $this->assertSame('confirmed', Db::get()->value('SELECT status FROM bookings WHERE id = ?', [$b['id']]), 'payment status is independent of booking status');
    }

    public function testForgedAndMismatchedCallbacksAreRejected(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $p] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $forged = $this->signed($p, 'paid');
        $forged['signature'] = str_repeat('0', 64);
        $this->assertSame(400, $this->webhook($forged));
        $this->assertSame(200, $this->webhook($this->signed($p, 'paid', 100)));
        $this->assertSame('pending', Db::get()->value('SELECT status FROM payments WHERE id = ?', [$p['id']]), 'wrong amount is never marked paid');
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.amount_mismatch'"));
    }

    public function testFailureThenRetry(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $p1] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $this->webhook($this->signed($p1, 'failed'));
        [$s, $d] = $this->call('GET', "/api/bookings/{$b['id']}", null, $c['token']);
        $this->assertSame('failed', $d['payment_status']);
        $this->assertTrue($d['can']['pay']);
        [$s, $p2] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $this->assertNotSame($p1['id'], $p2['id']);
        $this->webhook($this->signed($p2, 'paid'));
        $this->assertSame('paid', Db::get()->value('SELECT status FROM payments WHERE id = ?', [$p2['id']]));
    }

    public function testProviderOutageIsReportedHonestly(): void
    {
        PaymentService::useProvider(new class implements \Blissful\Payment\PaymentProvider {
            public function name(): string { return 'sandbox'; }
            public function mode(): string { return 'sandbox'; }
            public function initiate(array $payment, string $payerEmail, string $description): array { throw new \RuntimeException('timeout'); }
            public function parseCallback(array $fields): array { return ['valid' => false]; }
            public function poll(array $payment): ?array { return null; }
        });
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $x] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $this->assertSame([502, 'payment_provider_error'], [$s, $x['error']['code']]);
        $this->assertStringContainsString('Nothing was charged', $x['error']['message']);
        $this->assertSame('failed', Db::get()->value('SELECT status FROM payments'));
    }

    public function testRefundOnCancellationIsIdempotent(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $p] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $this->webhook($this->signed($p, 'paid'));
        $this->travel('+8 days'); // 50% tier
        $ct = $this->relogin($c);
        [$s, $d] = $this->call('POST', "/api/bookings/{$b['id']}/cancel", ['reason' => 'Venue flooded'], $ct);
        $this->assertSame(200, $s);
        $half = intdiv($b['price']['total_minor'], 2);
        $this->assertSame($half, (int) Db::get()->value('SELECT amount_minor FROM refunds'));
        $this->assertSame('partially_refunded', Db::get()->value('SELECT status FROM payments WHERE id = ?', [$p['id']]));
        // duplicate cancellation path (e.g. late callback) doesn't create a second refund
        $booking = Db::get()->one('SELECT * FROM bookings WHERE id = ?', [$b['id']]);
        Db::get()->tx(fn ($db) => (new PaymentService())->handleCancellation($db, $booking, ['refund_if_paid_minor' => $half, 'charge_minor' => $half]));
        $this->assertSame(1, (int) Db::get()->value('SELECT COUNT(*) FROM refunds'));
    }

    public function testPaidAfterCancellationIsRefunded(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $p] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $this->call('POST', "/api/bookings/{$b['id']}/cancel", ['reason' => 'Changed plans early'], $c['token']);
        $this->assertSame('cancelled', Db::get()->value('SELECT status FROM payments WHERE id = ?', [$p['id']]));
        $this->webhook($this->signed($p, 'paid')); // provider confirms late
        $this->assertSame('refunded', Db::get()->value('SELECT status FROM payments WHERE id = ?', [$p['id']]));
    }

    public function testPayoutQueuedAfterDisputeWindow(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $p] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $this->webhook($this->signed($p, 'paid'));
        $this->travel('+10 days 4 hours');
        $this->call('POST', "/api/bookings/{$b['id']}/done", [], $this->relogin($c));
        $this->call('POST', "/api/bookings/{$b['id']}/done", [], $this->relogin($m));
        $this->assertSame(0, PaymentService::queueDuePayouts(), 'not before the dispute window closes');
        $this->travel('+49 hours');
        $this->assertSame(1, PaymentService::queueDuePayouts());
        $this->assertSame(0, PaymentService::queueDuePayouts());
        $this->assertSame($b['price']['subtotal_minor'], (int) Db::get()->value('SELECT amount_minor FROM payouts'));
    }

    public function testIdempotencyKeyReplaysResponse(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        $h = ['Idempotency-Key' => 'pay-attempt-0001'];
        [$s1, $p1, $r1] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token'], $h);
        Db::get()->exec("UPDATE payments SET created_at = ?", [Clock::db(Clock::now()->modify('-2 hours'))]); // would normally start a new attempt
        [$s2, $p2, $r2] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token'], $h);
        $this->assertSame([$s1, $p1['id']], [$s2, $p2['id']]);
        $this->assertSame('true', $r2->headers['Idempotent-Replay'] ?? null);
        [$s3, $x] = $this->call('POST', "/api/bookings/{$b['id']}/payments", ['different' => true], $c['token'], $h);
        $this->assertSame(422, $s3);
    }

    public function testPaynowHashingAndStatusMapping(): void
    {
        \Blissful\Config::set('PAYNOW_INTEGRATION_ID', '1201');
        \Blissful\Config::set('PAYNOW_INTEGRATION_KEY', 'test-key-abc');
        \Blissful\Config::set('PAYNOW_MODE', 'test');
        $p = new PaynowProvider();
        $fields = ['reference' => 'BG1-AAAA', 'amount' => '110.00', 'paynowreference' => '999', 'pollurl' => 'https://www.paynow.co.zw/x', 'status' => 'Paid'];
        $fields['hash'] = strtoupper(hash('sha512', 'BG1-AAAA110.00999https://www.paynow.co.zw/xPaidtest-key-abc'));
        $parsed = $p->parseCallback($fields);
        $this->assertTrue($parsed['valid']);
        $this->assertSame('paid', $parsed['status']);
        $this->assertSame(11000, $parsed['amount_minor']);
        $fields['amount'] = '1.00';
        $this->assertFalse($p->parseCallback($fields)['valid'], 'tampering breaks the hash');
        $this->assertSame('pending', PaynowProvider::normalise('Sent'));
        $this->assertSame('cancelled', PaynowProvider::normalise('Cancelled'));
        $this->assertSame('110.05', PaynowProvider::decimal(11005));
        \Blissful\Config::set('PAYNOW_MODE', 'live');
        $this->expectException(\RuntimeException::class);
        new PaynowProvider(); // live is refused without PAYMENTS_LIVE_ENABLED
    }

    protected function tearDown(): void
    {
        foreach (['PAYNOW_INTEGRATION_ID', 'PAYNOW_INTEGRATION_KEY', 'PAYNOW_MODE'] as $k) {
            \Blissful\Config::set($k, null);
        }
        parent::tearDown();
    }
}
