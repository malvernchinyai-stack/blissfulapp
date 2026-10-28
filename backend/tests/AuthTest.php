<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Db;
use Blissful\Support\Mailer;

final class AuthTest extends TestCase
{
    public function testRegisterVerifyLoginAndMe(): void
    {
        $u = $this->user('ann@example.test');
        [$s, $me] = $this->call('GET', '/api/me', null, $u['token']);
        $this->assertSame(200, $s);
        $this->assertSame('ann@example.test', $me['email']);
        $this->assertSame(['client'], $me['roles']);
        $this->assertTrue($me['email_verified']);

        [$s, $b] = $this->call('POST', '/api/auth/login', ['email' => 'ANN@example.test', 'password' => 'Correct-horse-1']);
        $this->assertSame(200, $s);
        $this->assertNotEmpty($b['access_token']);
        // only hashes are stored
        $this->assertNull(Db::get()->value('SELECT 1 FROM auth_tokens WHERE token_hash = ?', [$b['access_token']]));
    }

    public function testRegistrationDoesNotRevealExistingAccounts(): void
    {
        $this->user('taken@example.test');
        [$s1, $b1] = $this->call('POST', '/api/auth/register', ['email' => 'taken@example.test', 'password' => 'Another-pass-2', 'display_name' => 'X', 'accept_terms' => true]);
        [$s2, $b2] = $this->call('POST', '/api/auth/register', ['email' => 'fresh@example.test', 'password' => 'Another-pass-2', 'display_name' => 'Y', 'accept_terms' => true]);
        $this->assertSame([$s1, $b1], [$s2, $b2]);
        // the existing owner's password must NOT change
        [$s] = $this->call('POST', '/api/auth/login', ['email' => 'taken@example.test', 'password' => 'Correct-horse-1']);
        $this->assertSame(200, $s);
        [$s1, $b1] = $this->call('POST', '/api/auth/forgot-password', ['email' => 'taken@example.test']);
        [$s2, $b2] = $this->call('POST', '/api/auth/forgot-password', ['email' => 'nobody@example.test']);
        $this->assertSame([$s1, $b1], [$s2, $b2]);
        [$s1, $b1] = $this->call('POST', '/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever-1']);
        [$s2, $b2] = $this->call('POST', '/api/auth/login', ['email' => 'taken@example.test', 'password' => 'wrong-pass-1']);
        $this->assertSame([401, 'invalid_credentials'], [$s1, $b1['error']['code']]);
        $this->assertSame([401, 'invalid_credentials'], [$s2, $b2['error']['code']]);
    }

    public function testValidationAndWeakPasswords(): void
    {
        [$s, $b] = $this->call('POST', '/api/auth/register', ['email' => 'not-an-email', 'password' => 'short', 'display_name' => '', 'accept_terms' => false]);
        $this->assertSame(422, $s);
        $this->assertArrayHasKey('email', $b['error']['fields']);
        $this->assertArrayHasKey('password', $b['error']['fields']);
        $this->assertArrayHasKey('display_name', $b['error']['fields']);
        $this->assertArrayHasKey('accept_terms', $b['error']['fields']);
        [$s] = $this->call('POST', '/api/auth/register', null);
        $this->assertSame(422, $s);
    }

    public function testUnverifiedCannotLoginAndCodeAttemptsAreLimited(): void
    {
        $this->call('POST', '/api/auth/register', ['email' => 'unv@example.test', 'password' => 'Correct-horse-1', 'display_name' => 'Unv', 'accept_terms' => true]);
        $code = $this->lastCode();
        [$s, $b] = $this->call('POST', '/api/auth/login', ['email' => 'unv@example.test', 'password' => 'Correct-horse-1']);
        $this->assertSame([403, 'email_not_verified'], [$s, $b['error']['code']]);
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            [$s] = $this->call('POST', '/api/auth/verify-email', ['email' => 'unv@example.test', 'code' => $wrong]);
            $this->assertSame(400, $s);
        }
        // the correct code no longer works after 5 failed attempts
        [$s] = $this->call('POST', '/api/auth/verify-email', ['email' => 'unv@example.test', 'code' => $code]);
        $this->assertSame(400, $s);
    }

    public function testBruteForceLockout(): void
    {
        $this->user('lock@example.test');
        for ($i = 0; $i < 5; $i++) {
            $this->call('POST', '/api/auth/login', ['email' => 'lock@example.test', 'password' => 'Wrong-pass-' . $i]);
        }
        [$s, $b] = $this->call('POST', '/api/auth/login', ['email' => 'lock@example.test', 'password' => 'Correct-horse-1']);
        $this->assertSame([429, 'account_locked'], [$s, $b['error']['code']]);
        $this->travel('+6 minutes');
        [$s] = $this->call('POST', '/api/auth/login', ['email' => 'lock@example.test', 'password' => 'Correct-horse-1']);
        $this->assertSame(200, $s);
    }

    public function testIpRateLimitOnLogin(): void
    {
        $this->user('rl@example.test');
        $codes = [];
        for ($i = 0; $i < 32; $i++) {
            [$s] = $this->call('POST', '/api/auth/login', ['email' => "x{$i}@example.test", 'password' => 'Wrong-pass-1'], null, [], '192.0.2.77');
            $codes[] = $s;
        }
        $this->assertContains(429, $codes);
    }

    public function testRefreshRotationAndReuseDetection(): void
    {
        $u = $this->user('ref@example.test');
        [$s, $r1] = $this->call('POST', '/api/auth/refresh', ['refresh_token' => $u['refresh']]);
        $this->assertSame(200, $s);
        // old access token is revoked with the family rotation
        [$s] = $this->call('GET', '/api/me', null, $u['token']);
        $this->assertSame(401, $s);
        // reuse of the rotated refresh token kills the whole session family
        [$s] = $this->call('POST', '/api/auth/refresh', ['refresh_token' => $u['refresh']]);
        $this->assertSame(401, $s);
        [$s] = $this->call('GET', '/api/me', null, $r1['access_token']);
        $this->assertSame(401, $s);
        [$s] = $this->call('POST', '/api/auth/refresh', ['refresh_token' => $r1['refresh_token']]);
        $this->assertSame(401, $s);
    }

    public function testAccessTokenExpires(): void
    {
        $u = $this->user('exp@example.test');
        $this->travel('+61 minutes');
        [$s, $b] = $this->call('GET', '/api/me', null, $u['token']);
        $this->assertSame(401, $s);
        [$s] = $this->call('POST', '/api/auth/refresh', ['refresh_token' => $u['refresh']]);
        $this->assertSame(200, $s);
    }

    public function testPasswordResetRevokesSessions(): void
    {
        $u = $this->user('reset@example.test');
        $this->call('POST', '/api/auth/forgot-password', ['email' => 'reset@example.test']);
        $code = $this->lastCode();
        [$s] = $this->call('POST', '/api/auth/reset-password', ['email' => 'reset@example.test', 'code' => $code, 'new_password' => 'Brand-new-pass-9']);
        $this->assertSame(200, $s);
        [$s] = $this->call('GET', '/api/me', null, $u['token']);
        $this->assertSame(401, $s);
        [$s] = $this->call('POST', '/api/auth/reset-password', ['email' => 'reset@example.test', 'code' => $code, 'new_password' => 'Another-pass-9']);
        $this->assertSame(400, $s, 'codes are single-use');
        [$s] = $this->call('POST', '/api/auth/login', ['email' => 'reset@example.test', 'password' => 'Brand-new-pass-9']);
        $this->assertSame(200, $s);
    }

    public function testRoleSwitchingUsesOneAccount(): void
    {
        $u = $this->user('both@example.test');
        [$s, $b] = $this->call('GET', '/api/requests/feed', null, $u['token']);
        $this->assertSame(403, $s);
        [$s, $me] = $this->call('POST', '/api/me/role', ['role' => 'musician'], $u['token']);
        $this->assertSame(['client', 'musician'], $me['roles']);
        $this->assertSame('musician', $me['active_role']);
        [$s, $b] = $this->call('GET', '/api/requests/feed', null, $u['token']);
        $this->assertSame([409, 'musician_profile_required'], [$s, $b['error']['code']]);
        $this->assertSame(1, (int) Db::get()->value('SELECT COUNT(*) FROM users WHERE email = ?', ['both@example.test']));
    }

    public function testLogoutAndChangePassword(): void
    {
        $u = $this->user('lo@example.test');
        [$s, $b] = $this->call('POST', '/api/me/password', ['current_password' => 'wrong', 'new_password' => 'Next-pass-123'], $u['token']);
        $this->assertSame(422, $s);
        [$s] = $this->call('POST', '/api/me/password', ['current_password' => 'Correct-horse-1', 'new_password' => 'Next-pass-123'], $u['token']);
        $this->assertSame(200, $s);
        [$s] = $this->call('POST', '/api/auth/logout', [], $u['token']);
        $this->assertSame(200, $s);
        [$s] = $this->call('GET', '/api/me', null, $u['token']);
        $this->assertSame(401, $s);
    }

    public function testAccountDeletion(): void
    {
        [$client, $musician] = $this->confirmedBooking();
        [$s, $b] = $this->call('POST', '/api/me/delete', ['password' => 'Correct-horse-1'], $client['token']);
        $this->assertSame([409, 'active_bookings'], [$s, $b['error']['code']]);

        $solo = $this->user('solo@example.test');
        $this->openRequest($solo['token']);
        [$s] = $this->call('POST', '/api/me/delete', ['password' => 'Correct-horse-1'], $solo['token']);
        $this->assertSame(200, $s);
        $row = Db::get()->one('SELECT * FROM users WHERE id = ?', [$solo['id']]);
        $this->assertSame('deleted', $row['status']);
        $this->assertStringNotContainsString('solo', $row['email']);
        $this->assertSame('cancelled', Db::get()->value('SELECT status FROM requests WHERE client_id = ?', [$solo['id']]));
        [$s] = $this->call('GET', '/api/me', null, $solo['token']);
        $this->assertSame(401, $s);
        [$s] = $this->call('POST', '/api/auth/login', ['email' => 'solo@example.test', 'password' => 'Correct-horse-1']);
        $this->assertSame(401, $s);
    }

    public function testEmailsAreLabelledAsTesting(): void
    {
        $this->call('POST', '/api/auth/register', ['email' => 'lbl@example.test', 'password' => 'Correct-horse-1', 'display_name' => 'Lb', 'accept_terms' => true]);
        $this->assertStringStartsWith('[Testing]', end(Mailer::$sent)['subject']);
    }
}
