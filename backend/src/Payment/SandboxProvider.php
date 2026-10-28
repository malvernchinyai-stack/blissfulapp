<?php
declare(strict_types=1);

namespace Blissful\Payment;

use Blissful\Config;

/**
 * TESTING ONLY. Simulates a hosted payment page and a signed provider callback.
 * No money moves. Refuses to run when APP_ENV=production.
 */
final class SandboxProvider implements PaymentProvider
{
    public function __construct()
    {
        if (Config::env() === 'production') {
            throw new \RuntimeException('The sandbox payment provider cannot run in production.');
        }
    }

    public function name(): string
    {
        return 'sandbox';
    }

    public function mode(): string
    {
        return 'sandbox';
    }

    public function initiate(array $payment, string $payerEmail, string $description): array
    {
        $ref = $payment['reference'];
        return [
            'redirect_url' => rtrim((string) Config::get('APP_URL', ''), '/') . '/sandbox/pay/' . rawurlencode($ref) . '?sig=' . self::sign(['reference' => $ref]),
            'poll_url' => null,
            'provider_ref' => 'SBX-' . strtoupper(bin2hex(random_bytes(4))),
        ];
    }

    public function parseCallback(array $fields): array
    {
        $sig = is_string($fields['signature'] ?? null) ? $fields['signature'] : '';
        unset($fields['signature']);
        $valid = $sig !== '' && hash_equals(self::sign($fields), $sig);
        $status = match ($fields['status'] ?? '') {
            'paid' => 'paid', 'failed' => 'failed', 'cancelled' => 'cancelled', default => 'pending',
        };
        return [
            'valid' => $valid,
            'reference' => is_string($fields['reference'] ?? null) ? $fields['reference'] : null,
            'status' => $status,
            'provider_ref' => is_string($fields['provider_ref'] ?? null) ? $fields['provider_ref'] : null,
            'amount_minor' => isset($fields['amount_minor']) && ctype_digit((string) $fields['amount_minor']) ? (int) $fields['amount_minor'] : null,
        ];
    }

    public function poll(array $payment): ?array
    {
        return null;
    }

    public static function sign(array $fields): string
    {
        ksort($fields);
        return hash_hmac('sha256', http_build_query($fields), Config::require('SANDBOX_WEBHOOK_SECRET'));
    }
}
