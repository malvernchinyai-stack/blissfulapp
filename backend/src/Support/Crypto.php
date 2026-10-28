<?php
declare(strict_types=1);

namespace Blissful\Support;

use Blissful\Config;

final class Crypto
{
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function sha256(string $v): string
    {
        return hash('sha256', $v);
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    /** Encrypts small secrets (e.g. TOTP seeds) with APP_KEY using libsodium secretbox. */
    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $k = base64_decode(Config::require('APP_KEY'), true);
        if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_KEY must be 32 random bytes, base64 encoded');
        }
        return $k;
    }
}
