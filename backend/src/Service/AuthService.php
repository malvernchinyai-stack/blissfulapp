<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Support\Audit;
use Blissful\Support\Clock;
use Blissful\Support\Crypto;
use Blissful\Support\Mailer;
use Blissful\Support\RateLimiter;
use Blissful\Support\Validator;

final class AuthService
{
    public const ACCESS_TTL_MIN = 60;
    public const REFRESH_TTL_DAYS = 30;
    private const CODE_TTL_MIN = 15;
    private const MAX_CODE_ATTEMPTS = 5;
    private const DUMMY_HASH = '$2y$10$JeeormsnOpgTRAz.E0R/..ki1h201A7c4BXyRcSrAQDf7OotpE2xe';

    /**
     * Sign-up. Always answers the same way whether or not the email exists (no account enumeration).
     * A 6-digit code is emailed; tokens are issued only after the code is verified.
     */
    public function register(array $input, string $ip): array
    {
        $v = new Validator($input);
        $email = $v->email('email');
        $password = $v->password('password');
        $name = $v->string('display_name', 2, 120);
        $phone = $v->phone('phone');
        $asMusician = $v->bool('as_musician');
        $acceptTerms = $v->bool('accept_terms');
        if (!$acceptTerms) {
            $v->addError('accept_terms', 'Please accept the terms and privacy policy.');
        }
        $v->done();
        RateLimiter::enforce('register:ip:' . $ip, 10, 3600);
        RateLimiter::enforce('register:email:' . $email, 3, 3600);

        $db = Db::get();
        $now = Clock::db();
        $existing = $db->one('SELECT id, email_verified_at, status FROM users WHERE email = ?', [$email]);
        if ($existing !== null && ($existing['email_verified_at'] !== null || $existing['status'] !== 'active')) {
            Mailer::send($email, 'Sign-up attempt on your Blissful Gigs account',
                "Someone tried to create a Blissful Gigs account with this email address.\n\n"
                . "If it was you, sign in instead or use \"Forgot password\". If it wasn't you, you can ignore this message.");
            return ['status' => 'verification_sent'];
        }
        $userId = $db->tx(function (Db $db) use ($existing, $email, $password, $name, $phone, $asMusician, $now) {
            $hash = Crypto::hashPassword($password);
            if ($existing !== null) {
                // Unverified account: the real mailbox owner is the only one who can complete it.
                $db->exec('UPDATE users SET password_hash=?, display_name=?, phone=?, is_musician=?, updated_at=? WHERE id=?',
                    [$hash, $name, $phone, $asMusician ? 1 : 0, $now, $existing['id']]);
                return (int) $existing['id'];
            }
            $id = $db->insert('users', [
                'email' => $email, 'phone' => $phone, 'password_hash' => $hash, 'display_name' => $name,
                'is_client' => 1, 'is_musician' => $asMusician ? 1 : 0, 'active_role' => $asMusician ? 'musician' : 'client',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('client_profiles', ['user_id' => $id, 'name' => $name, 'updated_at' => $now]);
            return $id;
        });
        $code = $this->issueCode($userId, 'email_verify');
        Mailer::send($email, 'Your Blissful Gigs verification code',
            "Your verification code is {$code}. It expires in " . self::CODE_TTL_MIN . " minutes.\n\nIf you didn't request this, ignore this email.");
        return ['status' => 'verification_sent'];
    }

    public function verifyEmail(array $input, string $ip): array
    {
        $v = new Validator($input);
        $email = $v->email('email');
        $code = $v->string('code', 6, 6);
        $device = $v->string('device_name', 0, 100, false, 'Android');
        $v->done();
        RateLimiter::enforce('verify:ip:' . $ip, 30, 3600);
        $user = Db::get()->one('SELECT * FROM users WHERE email = ? AND status = ?', [$email, 'active']);
        if ($user === null || !$this->consumeCode((int) $user['id'], 'email_verify', $code)) {
            throw new ApiError(400, 'invalid_code', 'That code is incorrect or has expired.');
        }
        Db::get()->exec('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?',
            [Clock::db(), Clock::db(), $user['id']]);
        return $this->issueTokens((int) $user['id'], $device);
    }

    public function resendVerification(array $input, string $ip): array
    {
        $v = new Validator($input);
        $email = $v->email('email');
        $v->done();
        RateLimiter::enforce('resend:ip:' . $ip, 10, 3600);
        RateLimiter::enforce('resend:email:' . $email, 3, 3600);
        $user = Db::get()->one('SELECT id FROM users WHERE email = ? AND email_verified_at IS NULL AND status = ?', [$email, 'active']);
        if ($user !== null) {
            $code = $this->issueCode((int) $user['id'], 'email_verify');
            Mailer::send($email, 'Your Blissful Gigs verification code', "Your verification code is {$code}.");
        }
        return ['status' => 'verification_sent'];
    }

    public function login(array $input, string $ip): array
    {
        $v = new Validator($input);
        $email = $v->email('email');
        $password = $v->string('password', 1, 200);
        $device = $v->string('device_name', 0, 100, false, 'Android');
        $v->done();
        RateLimiter::enforce('login:ip:' . $ip, 30, 900);
        RateLimiter::enforce('login:email:' . $email, 10, 900);

        $db = Db::get();
        $user = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
        $invalid = new ApiError(401, 'invalid_credentials', 'Email or password is incorrect.');
        if ($user === null || $user['status'] !== 'active') {
            password_verify($password, self::DUMMY_HASH); // equalise timing
            throw $invalid;
        }
        if ($user['locked_until'] !== null && Clock::fromDb($user['locked_until']) > Clock::now()) {
            throw new ApiError(429, 'account_locked', 'Too many failed attempts. Try again later or reset your password.');
        }
        if (!password_verify($password, $user['password_hash'])) {
            $fails = (int) $user['failed_logins'] + 1;
            $lock = $fails >= 5 ? Clock::db(Clock::now()->modify('+' . min(60, 5 * 2 ** ($fails - 5)) . ' minutes')) : null;
            $db->exec('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$fails, $lock, $user['id']]);
            throw $invalid;
        }
        if ($user['email_verified_at'] === null) {
            throw new ApiError(403, 'email_not_verified', 'Please verify your email address first.');
        }
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $db->exec('UPDATE users SET password_hash = ? WHERE id = ?', [Crypto::hashPassword($password), $user['id']]);
        }
        $db->exec('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
        return $this->issueTokens((int) $user['id'], $device);
    }

    /** Rotating refresh tokens. Re-use of a rotated token revokes the whole session family. */
    public function refresh(array $input, string $ip): array
    {
        $v = new Validator($input);
        $token = $v->string('refresh_token', 20, 200);
        $v->done();
        RateLimiter::enforce('refresh:ip:' . $ip, 120, 3600);
        $db = Db::get();
        // The reuse branch must COMMIT the family revocation, so it returns a marker instead of throwing inside the transaction.
        $result = $db->tx(function (Db $db) use ($token) {
            $row = $db->one('SELECT t.*, u.status FROM auth_tokens t JOIN users u ON u.id = t.user_id
                             WHERE t.token_hash = ? AND t.kind = ? FOR UPDATE', [Crypto::sha256($token), 'refresh']);
            if ($row === null) {
                return null;
            }
            if ($row['revoked_at'] !== null) {
                $db->exec('UPDATE auth_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', [Clock::db(), $row['family_id']]);
                Audit::record((int) $row['user_id'], 'auth.refresh_reuse_detected', 'user', (int) $row['user_id']);
                return null;
            }
            if (Clock::fromDb($row['expires_at']) < Clock::now() || $row['status'] !== 'active') {
                return null;
            }
            $db->exec('UPDATE auth_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', [Clock::db(), $row['family_id']]);
            return $this->issueTokens((int) $row['user_id'], (string) $row['device_name'], $row['family_id']);
        });
        if ($result === null) {
            throw ApiError::unauthorized();
        }
        return $result;
    }

    public function logout(array $user): void
    {
        Db::get()->exec('UPDATE auth_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', [Clock::db(), $user['_family']]);
    }

    public function forgotPassword(array $input, string $ip): array
    {
        $v = new Validator($input);
        $email = $v->email('email');
        $v->done();
        RateLimiter::enforce('forgot:ip:' . $ip, 10, 3600);
        if (RateLimiter::hit('forgot:email:' . $email, 3, 3600)) {
            $user = Db::get()->one('SELECT id FROM users WHERE email = ? AND status = ?', [$email, 'active']);
            if ($user !== null) {
                $code = $this->issueCode((int) $user['id'], 'password_reset');
                Mailer::send($email, 'Reset your Blissful Gigs password',
                    "Your password reset code is {$code}. It expires in " . self::CODE_TTL_MIN . " minutes.\n\nIf you didn't ask for this, you can ignore this email.");
            }
        }
        return ['status' => 'if_registered_code_sent'];
    }

    public function resetPassword(array $input, string $ip): array
    {
        $v = new Validator($input);
        $email = $v->email('email');
        $code = $v->string('code', 6, 6);
        $password = $v->password('new_password');
        $v->done();
        RateLimiter::enforce('reset:ip:' . $ip, 20, 3600);
        $db = Db::get();
        $user = $db->one('SELECT id FROM users WHERE email = ? AND status = ?', [$email, 'active']);
        if ($user === null || !$this->consumeCode((int) $user['id'], 'password_reset', $code)) {
            throw new ApiError(400, 'invalid_code', 'That code is incorrect or has expired.');
        }
        $uid = (int) $user['id'];
        $db->exec('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL,
                   email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?',
            [Crypto::hashPassword($password), Clock::db(), Clock::db(), $uid]);
        $db->exec('UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Clock::db(), $uid]);
        Audit::record($uid, 'auth.password_reset', 'user', $uid, [], $ip);
        return ['status' => 'password_changed'];
    }

    public function changePassword(array $user, array $input): array
    {
        $v = new Validator($input);
        $current = $v->string('current_password', 1, 200);
        $new = $v->password('new_password');
        $v->done();
        $db = Db::get();
        $hash = (string) $db->value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!password_verify($current, $hash)) {
            throw ApiError::validation(['current_password' => 'Current password is incorrect.']);
        }
        $db->exec('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [Crypto::hashPassword($new), Clock::db(), $user['id']]);
        $db->exec('UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND family_id <> ? AND revoked_at IS NULL',
            [Clock::db(), $user['id'], $user['_family']]);
        Audit::record((int) $user['id'], 'auth.password_changed', 'user', (int) $user['id']);
        return ['status' => 'password_changed'];
    }

    /** Resolves a bearer access token to a user row, or null. */
    public function authenticate(string $accessToken): ?array
    {
        $row = Db::get()->one(
            'SELECT u.*, t.family_id AS _family FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.kind = ? AND t.revoked_at IS NULL AND t.expires_at > ? AND u.status = ?',
            [Crypto::sha256($accessToken), 'access', Clock::db(), 'active']
        );
        return $row;
    }

    public function switchRole(array $user, array $input): array
    {
        $v = new Validator($input);
        $role = $v->enum('role', ['client', 'musician']);
        $v->done();
        if ($role === 'musician' && !(int) $user['is_musician']) {
            Db::get()->exec('UPDATE users SET is_musician = 1 WHERE id = ?', [$user['id']]);
        }
        Db::get()->exec('UPDATE users SET active_role = ?, updated_at = ? WHERE id = ?', [$role, Clock::db(), $user['id']]);
        return $this->me((int) $user['id']);
    }

    public function me(int $userId): array
    {
        $u = Db::get()->one('SELECT * FROM users WHERE id = ?', [$userId]);
        $hasMusicianProfile = (bool) Db::get()->value('SELECT 1 FROM musician_profiles WHERE user_id = ?', [$userId]);
        $staff = Db::get()->value('SELECT role FROM staff WHERE user_id = ?', [$userId]);
        return [
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'phone' => $u['phone'],
            'display_name' => $u['display_name'],
            'roles' => array_values(array_filter(['client', (int) $u['is_musician'] ? 'musician' : null])),
            'active_role' => $u['active_role'],
            'has_musician_profile' => $hasMusicianProfile,
            'email_verified' => $u['email_verified_at'] !== null,
            'timezone' => $u['timezone'],
            'is_staff' => $staff !== null,
            'created_at' => Clock::iso($u['created_at']),
        ];
    }

    /**
     * Account deletion: blocked while bookings are active; otherwise personal data is erased/anonymised.
     * Booking, payment and review records are retained in anonymised form for accounting and dispute history.
     */
    public function deleteAccount(array $user, array $input): array
    {
        $v = new Validator($input);
        $password = $v->string('password', 1, 200);
        $v->done();
        $db = Db::get();
        if (!password_verify($password, (string) $db->value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]))) {
            throw ApiError::validation(['password' => 'Password is incorrect.']);
        }
        $uid = (int) $user['id'];
        $active = (int) $db->value("SELECT COUNT(*) FROM bookings WHERE (client_id = ? OR musician_id = ?) AND status IN ('confirmed','in_progress','disputed')", [$uid, $uid]);
        if ($active > 0) {
            throw ApiError::conflict('active_bookings', 'Cancel or complete your active bookings before deleting your account.');
        }
        $db->tx(function (Db $db) use ($uid) {
            $now = Clock::db();
            $db->exec("UPDATE requests SET status = 'cancelled', updated_at = ? WHERE client_id = ? AND status IN ('draft','open')", [$now, $uid]);
            $db->exec("UPDATE offers SET status = 'withdrawn', updated_at = ? WHERE musician_id = ? AND status = 'submitted'", [$now, $uid]);
            $db->exec("UPDATE users SET email = ?, phone = NULL, display_name = 'Deleted user', password_hash = ?, status = 'deleted',
                       deleted_at = ?, updated_at = ? WHERE id = ?",
                ["deleted+{$uid}@invalid.blissful", Crypto::hashPassword(Crypto::token()), $now, $now, $uid]);
            $db->exec("UPDATE client_profiles SET name = 'Deleted user', bio = NULL, photo_file_id = NULL, city = NULL, area = NULL WHERE user_id = ?", [$uid]);
            $db->exec("UPDATE musician_profiles SET stage_name = 'Deleted user', bio = NULL, photo_file_id = NULL, lat = NULL, lng = NULL,
                       accepting_bookings = 0, equipment_provided = NULL, equipment_required = NULL WHERE user_id = ?", [$uid]);
            $db->exec('DELETE FROM portfolio_items WHERE musician_id = ?', [$uid]);
            $db->exec('DELETE FROM device_tokens WHERE user_id = ?', [$uid]);
            $db->exec('UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$now, $uid]);
            $db->exec('DELETE FROM notifications WHERE user_id = ?', [$uid]);
            $db->exec('UPDATE files SET deleted_at = ? WHERE owner_id = ? AND deleted_at IS NULL AND (retain_until IS NULL OR retain_until < ?)', [$now, $uid, $now]);
        });
        (new FileService())->purgeDeleted();
        Audit::record($uid, 'account.deleted', 'user', $uid);
        return ['status' => 'deleted'];
    }

    private function issueTokens(int $userId, string $device, ?string $family = null): array
    {
        $db = Db::get();
        $family ??= bin2hex(random_bytes(16));
        $access = Crypto::token();
        $refresh = Crypto::token();
        $now = Clock::now();
        foreach ([['access', $access, $now->modify('+' . self::ACCESS_TTL_MIN . ' minutes')],
                  ['refresh', $refresh, $now->modify('+' . self::REFRESH_TTL_DAYS . ' days')]] as [$kind, $tok, $exp]) {
            $db->insert('auth_tokens', [
                'user_id' => $userId, 'family_id' => $family, 'token_hash' => Crypto::sha256($tok), 'kind' => $kind,
                'device_name' => mb_substr($device, 0, 100), 'expires_at' => Clock::db($exp), 'created_at' => Clock::db($now),
            ]);
        }
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => self::ACCESS_TTL_MIN * 60,
            'user' => $this->me($userId),
        ];
    }

    private function issueCode(int $userId, string $purpose): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $db = Db::get();
        $db->exec('UPDATE verification_codes SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [Clock::db(), $userId, $purpose]);
        $db->insert('verification_codes', [
            'user_id' => $userId, 'purpose' => $purpose, 'code_hash' => Crypto::sha256($userId . ':' . $code),
            'expires_at' => Clock::db(Clock::now()->modify('+' . self::CODE_TTL_MIN . ' minutes')), 'created_at' => Clock::db(),
        ]);
        return $code;
    }

    private function consumeCode(int $userId, string $purpose, string $code): bool
    {
        $db = Db::get();
        return $db->tx(function (Db $db) use ($userId, $purpose, $code) {
            $row = $db->one('SELECT * FROM verification_codes WHERE user_id = ? AND purpose = ? AND used_at IS NULL
                             ORDER BY id DESC LIMIT 1 FOR UPDATE', [$userId, $purpose]);
            if ($row === null || Clock::fromDb($row['expires_at']) < Clock::now() || (int) $row['attempts'] >= self::MAX_CODE_ATTEMPTS) {
                return false;
            }
            if (!hash_equals($row['code_hash'], Crypto::sha256($userId . ':' . $code))) {
                $db->exec('UPDATE verification_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
                return false;
            }
            $db->exec('UPDATE verification_codes SET used_at = ? WHERE id = ?', [Clock::db(), $row['id']]);
            return true;
        });
    }
}
