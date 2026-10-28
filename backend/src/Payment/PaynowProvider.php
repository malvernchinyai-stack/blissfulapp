<?php
declare(strict_types=1);

namespace Blissful\Payment;

use Blissful\Config;
use Blissful\Support\Log;

/**
 * Paynow Zimbabwe (hosted checkout: EcoCash, OneMoney, Visa/Mastercard on Paynow's own page).
 * Implemented from Paynow's published integration protocol: urlencoded POSTs, SHA-512 hash of all
 * field values in order + integration key, uppercase hex.
 *
 * STATUS: NOT YET VERIFIED against Paynow's servers (no merchant credentials were available).
 * Live mode additionally requires PAYMENTS_LIVE_ENABLED=true. See docs/PAYMENTS.md.
 */
final class PaynowProvider implements PaymentProvider
{
    private const INIT_URL = 'https://www.paynow.co.zw/interface/initiatetransaction';
    private const PAID_STATUSES = ['paid', 'awaiting delivery', 'delivered'];
    private const FAILED_STATUSES = ['failed', 'disputed'];
    private const CANCELLED_STATUSES = ['cancelled'];

    private string $integrationId;
    private string $integrationKey;
    private string $mode;

    public function __construct()
    {
        $this->integrationId = Config::require('PAYNOW_INTEGRATION_ID');
        $this->integrationKey = Config::require('PAYNOW_INTEGRATION_KEY');
        $this->mode = Config::get('PAYNOW_MODE', 'test') === 'live' ? 'live' : 'sandbox';
        if ($this->mode === 'live' && !Config::bool('PAYMENTS_LIVE_ENABLED')) {
            throw new \RuntimeException('Live Paynow payments are disabled. Set PAYMENTS_LIVE_ENABLED=true only after written authorisation.');
        }
    }

    public function name(): string
    {
        return 'paynow';
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function initiate(array $payment, string $payerEmail, string $description): array
    {
        $base = rtrim((string) Config::require('APP_URL'), '/');
        $fields = [
            'id' => $this->integrationId,
            'reference' => $payment['reference'],
            'amount' => self::decimal((int) $payment['amount_minor']),
            'additionalinfo' => mb_substr($description, 0, 100),
            'returnurl' => $base . '/payment-return?ref=' . rawurlencode($payment['reference']),
            'resulturl' => $base . '/webhooks/paynow',
            'authemail' => $payerEmail,
            'status' => 'Message',
        ];
        $fields['hash'] = $this->hash($fields);
        $resp = $this->post(self::INIT_URL, $fields);
        if ($resp === null || strtolower($resp['status'] ?? '') !== 'ok' || !$this->verifyHash($resp)) {
            Log::warning('paynow_initiate_failed', ['reference' => $payment['reference'], 'status' => $resp['status'] ?? null, 'error' => $resp['error'] ?? null]);
            throw new \RuntimeException('Paynow did not accept the payment request.');
        }
        return ['redirect_url' => $resp['browserurl'], 'poll_url' => $resp['pollurl'], 'provider_ref' => null];
    }

    public function parseCallback(array $fields): array
    {
        $valid = $this->verifyHash($fields);
        return [
            'valid' => $valid,
            'reference' => is_string($fields['reference'] ?? null) ? $fields['reference'] : null,
            'status' => self::normalise((string) ($fields['status'] ?? '')),
            'provider_ref' => is_string($fields['paynowreference'] ?? null) ? $fields['paynowreference'] : null,
            'amount_minor' => isset($fields['amount']) ? self::minor((string) $fields['amount']) : null,
            'poll_url' => $fields['pollurl'] ?? null,
        ];
    }

    public function poll(array $payment): ?array
    {
        if (empty($payment['poll_url']) || !str_starts_with((string) $payment['poll_url'], 'https://www.paynow.co.zw/')) {
            return null;
        }
        $resp = $this->post((string) $payment['poll_url'], []);
        return $resp === null ? null : $this->parseCallback($resp);
    }

    /** SHA-512 over concatenated values (excluding 'hash') + integration key, uppercase. */
    public function hash(array $fields): string
    {
        $s = '';
        foreach ($fields as $k => $v) {
            if (strtolower((string) $k) !== 'hash') {
                $s .= $v;
            }
        }
        return strtoupper(hash('sha512', $s . $this->integrationKey));
    }

    public function verifyHash(array $fields): bool
    {
        $given = (string) ($fields['hash'] ?? '');
        return $given !== '' && hash_equals($this->hash($fields), strtoupper($given));
    }

    private function post(string $url, array $fields): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $code !== 200) {
            return null;
        }
        parse_str($body, $out);
        return $out;
    }

    public static function normalise(string $status): string
    {
        $s = strtolower(trim($status));
        return match (true) {
            in_array($s, self::PAID_STATUSES, true) => 'paid',
            in_array($s, self::FAILED_STATUSES, true) => 'failed',
            in_array($s, self::CANCELLED_STATUSES, true) => 'cancelled',
            default => 'pending',
        };
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function minor(string $decimal): ?int
    {
        return \Blissful\Support\Money::parseDecimal(trim($decimal), 'USD');
    }
}
