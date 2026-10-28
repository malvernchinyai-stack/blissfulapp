<?php
declare(strict_types=1);

namespace Blissful\Support;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) compatible with Google Authenticator, Authy, Microsoft Authenticator. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $msg = pack('N*', 0, $step);
        $h = hash_hmac('sha1', $msg, $key, true);
        $offset = ord($h[19]) & 0x0f;
        $bin = ((ord($h[$offset]) & 0x7f) << 24) | (ord($h[$offset + 1]) << 16) | (ord($h[$offset + 2]) << 8) | ord($h[$offset + 3]);
        return str_pad((string) ($bin % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Returns the matched time step (to block replay) or null. Allows ±1 step of clock drift. */
    public static function verify(string $secret, string $code, ?int $lastUsedStep = null): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = intdiv(Clock::now()->getTimestamp(), 30);
        for ($i = -1; $i <= 1; $i++) {
            $step = $now + $i;
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer = 'Blissful Gigs'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
    }

    private static function base32Decode(string $s): string
    {
        $s = strtoupper(rtrim($s, '='));
        $bits = '';
        foreach (str_split($s) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
