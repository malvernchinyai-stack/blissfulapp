<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Config;
use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Payment\PaymentProvider;
use Blissful\Payment\PaynowProvider;
use Blissful\Payment\SandboxProvider;
use Blissful\Support\Audit;
use Blissful\Support\Clock;
use Blissful\Support\Log;
use Blissful\Support\Money;

/**
 * Payment status is tracked independently of booking status. Blissful Studios collects the client's
 * payment as merchant of record and pays musicians out after the dispute window; this is NOT escrow.
 * Every state change is idempotent: duplicate callbacks, retries and polls cannot double-apply.
 */
final class PaymentService
{
    private static ?PaymentProvider $override = null;

    public static function useProvider(?PaymentProvider $p): void
    {
        self::$override = $p;
    }

    public static function provider(?string $name = null): PaymentProvider
    {
        if (self::$override !== null && ($name === null || $name === self::$override->name())) {
            return self::$override;
        }
        $name ??= Config::get('PAYMENT_PROVIDER', 'sandbox');
        return match ($name) {
            'paynow' => new PaynowProvider(),
            'sandbox' => new SandboxProvider(),
            default => throw new \RuntimeException('Unknown payment provider'),
        };
    }

    public function start(int $userId, int $bookingId): array
    {
        $db = Db::get();
        $b = (new BookingService())->participantBooking($bookingId, $userId);
        if ((int) $b['client_id'] !== $userId) {
            throw ApiError::forbidden('Only the client pays for a booking.');
        }
        if (!in_array($b['status'], ['confirmed', 'in_progress'], true)) {
            throw ApiError::conflict('not_payable', 'This booking cannot be paid.');
        }
        $existing = $db->one("SELECT * FROM payments WHERE booking_id = ? AND status IN ('paid','pending','partially_refunded') ORDER BY id DESC LIMIT 1", [$bookingId]);
        if ($existing !== null && $existing['status'] !== 'pending') {
            throw ApiError::conflict('already_paid', 'This booking has already been paid.');
        }
        if ($existing !== null && Clock::fromDb($existing['created_at']) > Clock::now()->modify('-30 minutes')) {
            return $this->format($existing);
        }
        if ($existing !== null) {
            $db->exec("UPDATE payments SET status = 'cancelled', updated_at = ? WHERE id = ? AND status = 'pending'", [Clock::db(), $existing['id']]);
        }
        $provider = self::provider();
        $reference = 'BG' . $bookingId . '-' . strtoupper(bin2hex(random_bytes(4)));
        $now = Clock::db();
        $id = $db->insert('payments', [
            'booking_id' => $bookingId, 'payer_id' => $userId, 'provider' => $provider->name(), 'mode' => $provider->mode(),
            'reference' => $reference, 'idempotency_key' => 'pay:' . $reference, 'amount_minor' => (int) $b['total_minor'],
            'currency' => $b['currency'], 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $payment = $db->one('SELECT * FROM payments WHERE id = ?', [$id]);
        $email = (string) $db->value('SELECT email FROM users WHERE id = ?', [$userId]);
        try {
            $init = $provider->initiate($payment, $email, 'Blissful Gigs booking #' . $bookingId);
        } catch (\Throwable $e) {
            $db->exec("UPDATE payments SET status = 'failed', updated_at = ? WHERE id = ?", [Clock::db(), $id]);
            Log::error('payment_initiate_failed', ['payment_id' => $id, 'error' => $e->getMessage()]);
            throw new ApiError(502, 'payment_provider_error', 'The payment service could not start your payment. Nothing was charged. Please try again.');
        }
        $db->exec('UPDATE payments SET redirect_url = ?, poll_url = ?, provider_ref = ?, updated_at = ? WHERE id = ?',
            [$init['redirect_url'], $init['poll_url'], $init['provider_ref'], Clock::db(), $id]);
        return $this->format($db->one('SELECT * FROM payments WHERE id = ?', [$id]));
    }

    public function forBooking(int $userId, int $bookingId): array
    {
        (new BookingService())->participantBooking($bookingId, $userId);
        $db = Db::get();
        foreach ($db->all("SELECT * FROM payments WHERE booking_id = ? AND status = 'pending' AND poll_url IS NOT NULL", [$bookingId]) as $p) {
            $this->pollOne($p);
        }
        return array_map(fn ($p) => $this->format($p), $db->all('SELECT * FROM payments WHERE booking_id = ? ORDER BY id DESC', [$bookingId]));
    }

    /** Provider callback. Returns HTTP status to send back. */
    public function handleCallback(string $providerName, array $fields, string $raw): int
    {
        $db = Db::get();
        try {
            $provider = self::provider($providerName);
        } catch (\Throwable) {
            return 404;
        }
        $parsed = $provider->parseCallback($fields);
        $eventKey = hash('sha256', $providerName . '|' . $raw);
        $inserted = $db->exec('INSERT IGNORE INTO webhook_events (provider, event_key, payload, signature_valid, received_at) VALUES (?,?,?,?,?)',
            [$providerName, $eventKey, mb_substr(json_encode(\Blissful\Support\Log::redact($fields)), 0, 60000), $parsed['valid'] ? 1 : 0, Clock::db()]);
        if (!$parsed['valid']) {
            Log::warning('webhook_bad_signature', ['provider' => $providerName]);
            return 400;
        }
        if ($inserted === 0) {
            $processed = $db->value('SELECT processed_at FROM webhook_events WHERE provider = ? AND event_key = ?', [$providerName, $eventKey]);
            if ($processed !== null) {
                return 200; // duplicate delivery — already applied
            }
        }
        $payment = $parsed['reference'] !== null ? $db->one('SELECT * FROM payments WHERE reference = ? AND provider = ?', [$parsed['reference'], $providerName]) : null;
        if ($payment === null) {
            return 404;
        }
        // Paynow recommends confirming via poll before trusting a status.
        if ($providerName === 'paynow' && $parsed['status'] === 'paid') {
            $polled = $provider->poll($payment);
            if ($polled === null || !$polled['valid'] || $polled['status'] !== 'paid') {
                Log::warning('paynow_poll_mismatch', ['payment_id' => (int) $payment['id']]);
                return 202;
            }
        }
        $this->applyStatus((int) $payment['id'], $parsed['status'], $parsed['provider_ref'], $parsed['amount_minor']);
        $db->exec('UPDATE webhook_events SET processed_at = ? WHERE provider = ? AND event_key = ?', [Clock::db(), $providerName, $eventKey]);
        return 200;
    }

    /** Monotonic status transitions; amount must match exactly before anything is marked paid. */
    public function applyStatus(int $paymentId, string $status, ?string $providerRef, ?int $amountMinor): void
    {
        Db::get()->tx(function (Db $db) use ($paymentId, $status, $providerRef, $amountMinor) {
            $p = $db->one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            $allowed = [
                'pending' => ['paid', 'failed', 'cancelled'],
                'failed' => ['paid'],
                'cancelled' => ['paid'],
            ];
            if (!in_array($status, $allowed[$p['status']] ?? [], true)) {
                return;
            }
            if ($status === 'paid' && $amountMinor !== null && $amountMinor !== (int) $p['amount_minor']) {
                Log::error('payment_amount_mismatch', ['payment_id' => $paymentId, 'expected' => (int) $p['amount_minor'], 'got' => $amountMinor]);
                Audit::record(null, 'payment.amount_mismatch', 'payment', $paymentId, ['expected' => (int) $p['amount_minor'], 'got' => $amountMinor]);
                return;
            }
            $now = Clock::db();
            $db->exec('UPDATE payments SET status = ?, provider_ref = COALESCE(?, provider_ref), paid_at = IF(? = \'paid\', ?, paid_at), updated_at = ? WHERE id = ?',
                [$status, $providerRef, $status, $now, $now, $paymentId]);
            $b = $db->one('SELECT * FROM bookings WHERE id = ?', [$p['booking_id']]);
            if ($status === 'paid') {
                $db->insert('booking_events', ['booking_id' => $b['id'], 'actor_id' => null, 'event' => 'payment_received',
                    'detail_json' => json_encode(['amount_minor' => (int) $p['amount_minor']]), 'created_at' => $now]);
                NotificationService::send((int) $p['payer_id'], 'payment', 'Payment received', Money::format((int) $p['amount_minor'], $p['currency']) . ' received. Thank you!',
                    ['booking_id' => (int) $b['id']], "paid:{$paymentId}");
                NotificationService::send((int) $b['musician_id'], 'payment', 'Client payment received', 'The client has paid for this booking.',
                    ['booking_id' => (int) $b['id']], "paid_m:{$paymentId}");
                if ($b['status'] === 'cancelled') {
                    // Paid after cancellation (late callback): refund per the recorded charge.
                    $this->handleCancellation($db, $b, ['refund_if_paid_minor' => (int) $b['total_minor'] - (int) $b['cancellation_charge_minor'], 'charge_minor' => (int) $b['cancellation_charge_minor']]);
                }
            } else {
                NotificationService::send((int) $p['payer_id'], 'payment', 'Payment not completed', 'Your payment did not go through. No booking terms have changed; you can try again.',
                    ['booking_id' => (int) $b['id']], "payfail:{$paymentId}:{$status}");
            }
        });
    }

    /** Called inside the booking cancellation transaction. Creates at most one refund per booking. */
    public function handleCancellation(Db $db, array $booking, array $quote, ?int $staffId = null): void
    {
        $now = Clock::db();
        $db->exec("UPDATE payments SET status = 'cancelled', updated_at = ? WHERE booking_id = ? AND status = 'pending'", [$now, $booking['id']]);
        $paid = $db->one("SELECT * FROM payments WHERE booking_id = ? AND status IN ('paid','partially_refunded') ORDER BY id DESC LIMIT 1 FOR UPDATE", [$booking['id']]);
        if ($paid === null) {
            return;
        }
        $refund = min((int) $quote['refund_if_paid_minor'], (int) $paid['amount_minor'] - (int) $paid['refunded_minor']);
        if ($refund <= 0) {
            return;
        }
        $key = 'refund:booking:' . $booking['id'];
        $inserted = $db->exec('INSERT IGNORE INTO refunds (payment_id, amount_minor, reason, idempotency_key, status, method, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$paid['id'], $refund, 'Booking cancelled', $key, 'pending', $paid['mode'] === 'sandbox' ? 'sandbox' : 'manual_paynow', $staffId, $now]);
        if ($inserted === 0) {
            return;
        }
        if ($paid['mode'] === 'sandbox') {
            $rid = (int) $db->value('SELECT id FROM refunds WHERE idempotency_key = ?', [$key]);
            self::completeRefund($db, $rid, $staffId);
        }
    }

    /** Finance staff mark a manual (Paynow merchant portal) refund as done; sandbox refunds complete automatically. */
    public static function completeRefund(Db $db, int $refundId, ?int $staffId): void
    {
        $r = $db->one('SELECT * FROM refunds WHERE id = ? FOR UPDATE', [$refundId]);
        if ($r === null || $r['status'] !== 'pending') {
            return;
        }
        $p = $db->one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$r['payment_id']]);
        $refunded = (int) $p['refunded_minor'] + (int) $r['amount_minor'];
        $status = $refunded >= (int) $p['amount_minor'] ? 'refunded' : 'partially_refunded';
        $now = Clock::db();
        $db->exec("UPDATE refunds SET status = 'completed', completed_at = ? WHERE id = ?", [$now, $refundId]);
        $db->exec('UPDATE payments SET refunded_minor = ?, status = ?, updated_at = ? WHERE id = ?', [$refunded, $status, $now, $p['id']]);
        NotificationService::send((int) $p['payer_id'], 'payment', 'Refund processed', Money::format((int) $r['amount_minor'], $p['currency']) . ' has been refunded.',
            ['booking_id' => (int) $p['booking_id']], "refund:{$refundId}");
        if ($staffId !== null) {
            Audit::record($staffId, 'refund.completed', 'refund', $refundId, ['amount_minor' => (int) $r['amount_minor']]);
        }
    }

    /** Cron: once the dispute window closes on a paid, completed booking, queue the musician payout. */
    public static function queueDuePayouts(): int
    {
        $db = Db::get();
        $rows = $db->all("SELECT b.* FROM bookings b
            WHERE b.status = 'completed' AND b.dispute_window_ends_at <= ?
              AND EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status IN ('paid','partially_refunded'))
              AND NOT EXISTS (SELECT 1 FROM payouts po WHERE po.booking_id = b.id) LIMIT 200", [Clock::db()]);
        $n = 0;
        foreach ($rows as $b) {
            $n += $db->exec('INSERT IGNORE INTO payouts (booking_id, musician_id, amount_minor, currency, status, created_at) VALUES (?,?,?,?,?,?)',
                [$b['id'], $b['musician_id'], (int) $b['price_minor'] + (int) $b['extras_minor'], $b['currency'], 'pending', Clock::db()]);
        }
        return $n;
    }

    public function pollPending(): int
    {
        $n = 0;
        foreach (Db::get()->all("SELECT * FROM payments WHERE status = 'pending' AND poll_url IS NOT NULL AND created_at > ? LIMIT 50",
            [Clock::db(Clock::now()->modify('-2 days'))]) as $p) {
            $this->pollOne($p);
            $n++;
        }
        Db::get()->exec("UPDATE payments SET status = 'cancelled', updated_at = ? WHERE status = 'pending' AND created_at < ?",
            [Clock::db(), Clock::db(Clock::now()->modify('-2 days'))]);
        return $n;
    }

    private function pollOne(array $p): void
    {
        try {
            $res = self::provider($p['provider'])->poll($p);
        } catch (\Throwable $e) {
            Log::warning('payment_poll_failed', ['payment_id' => (int) $p['id'], 'error' => $e->getMessage()]);
            return;
        }
        if ($res !== null && $res['valid'] && $res['status'] !== 'pending') {
            $this->applyStatus((int) $p['id'], $res['status'], $res['provider_ref'], $res['amount_minor']);
        }
    }

    private function format(array $p): array
    {
        return [
            'id' => (int) $p['id'], 'booking_id' => (int) $p['booking_id'], 'provider' => $p['provider'], 'mode' => $p['mode'],
            'is_test_payment' => $p['mode'] === 'sandbox', 'reference' => $p['reference'], 'amount_minor' => (int) $p['amount_minor'],
            'currency' => $p['currency'], 'status' => $p['status'], 'refunded_minor' => (int) $p['refunded_minor'],
            'checkout_url' => $p['status'] === 'pending' ? $p['redirect_url'] : null, 'paid_at' => Clock::iso($p['paid_at']),
            'created_at' => Clock::iso($p['created_at']),
        ];
    }
}
