<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Db;
use Blissful\Http\Kernel;
use Blissful\Http\Request;
use Blissful\Support\Clock;
use Blissful\Support\Crypto;
use Blissful\Support\Settings;
use Blissful\Support\Totp;

final class AdminWebTest extends TestCase
{
    private function web(string $method, string $path, array $post = [], ?string $cookie = null): \Blissful\Http\Response
    {
        [$p, $qs] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($qs, $query);
        $h = $cookie ? ['cookie' => 'bg_admin=' . $cookie] : [];
        return Kernel::handle(new Request($method, $p, $query, $h, http_build_query($post), $post, [], '10.9.9.' . random_int(1, 250)));
    }

    private function staff(string $role): array
    {
        $now = Clock::db();
        $id = Db::get()->insert('users', ['email' => "{$role}@staff.test", 'password_hash' => Crypto::hashPassword('Staff-pass-123'), 'display_name' => ucfirst($role),
            'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        Db::get()->insert('client_profiles', ['user_id' => $id, 'name' => $role, 'updated_at' => $now]);
        Db::get()->insert('staff', ['user_id' => $id, 'role' => $role, 'created_at' => $now]);
        return ['id' => $id, 'email' => "{$role}@staff.test"];
    }

    /** Full sign-in: password, TOTP enrolment, returns [cookie, csrf]. */
    private function signIn(string $role): array
    {
        $s = $this->staff($role);
        $res = $this->web('POST', '/admin/login', ['email' => $s['email'], 'password' => 'Staff-pass-123']);
        $this->assertSame(303, $res->status);
        preg_match('/bg_admin=([^;]+)/', $res->headers['Set-Cookie'], $m);
        $cookie = $m[1];
        $this->assertStringContainsString('HttpOnly', $res->headers['Set-Cookie']);
        $this->assertStringContainsString('SameSite=Strict', $res->headers['Set-Cookie']);
        // before TOTP, nothing is reachable
        $page = $this->web('GET', '/admin/users', [], $cookie);
        $this->assertStringContainsString('two-step', strtolower($page->body));
        $secret = Crypto::decrypt((string) Db::get()->value('SELECT totp_secret_enc FROM staff WHERE user_id = ?', [$s['id']]));
        $csrf = (string) Db::get()->value('SELECT csrf_token FROM admin_sessions WHERE user_id = ?', [$s['id']]);
        $bad = $this->web('POST', '/admin/totp', ['csrf' => $csrf, 'code' => '000000'], $cookie);
        $this->assertStringContainsString('not valid', $bad->body);
        $ok = $this->web('POST', '/admin/totp', ['csrf' => $csrf, 'code' => Totp::code($secret, intdiv(Clock::now()->getTimestamp(), 30))], $cookie);
        $this->assertSame(303, $ok->status);
        return [$cookie, $csrf, $s];
    }

    public function testLoginRequiresPasswordAndTotp(): void
    {
        $this->staff('moderator');
        $res = $this->web('POST', '/admin/login', ['email' => 'moderator@staff.test', 'password' => 'wrong']);
        $this->assertSame(401, $res->status);
        $plain = $this->user('notstaff@example.test');
        $res = $this->web('POST', '/admin/login', ['email' => 'notstaff@example.test', 'password' => 'Correct-horse-1']);
        $this->assertSame(401, $res->status, 'regular users cannot sign in to admin');
        [$cookie] = $this->signIn('superadmin');
        $page = $this->web('GET', '/admin/', [], $cookie);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('TESTING ENVIRONMENT', $page->body);
        $this->assertStringContainsString("default-src 'none'", $page->headers['Content-Security-Policy']);
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.totp_enrolled'"));
    }

    public function testCsrfIsEnforced(): void
    {
        [$cookie, $csrf] = $this->signIn('superadmin');
        $res = $this->web('POST', '/admin/settings', ['fee_percent' => '50'], $cookie);
        $this->assertSame(400, $res->status);
        $this->assertSame(1000, Settings::int('platform_fee_bps', 0));
    }

    public function testRolePermissions(): void
    {
        [$cookie] = $this->signIn('finance');
        $this->assertSame(200, $this->web('GET', '/admin/payments', [], $cookie)->status);
        $this->assertSame(403, $this->web('GET', '/admin/settings', [], $cookie)->status);
        $this->assertSame(403, $this->web('GET', '/admin/users', [], $cookie)->status);
        $this->assertSame(403, $this->web('GET', '/admin/audit', [], $cookie)->status);
    }

    public function testSettingsChangeIsAudited(): void
    {
        [$cookie, $csrf] = $this->signIn('superadmin');
        $res = $this->web('POST', '/admin/settings', ['csrf' => $csrf, 'fee_percent' => '12.5', 'dispute_window_hours' => '72',
            'tier_hours_0' => '96', 'tier_pct_0' => '0', 'tier_hours_1' => '48', 'tier_pct_1' => '25', 'tier_hours_2' => '0', 'tier_pct_2' => '100'], $cookie);
        $this->assertSame(303, $res->status);
        Settings::flush();
        $this->assertSame(1250, Settings::int('platform_fee_bps', 0));
        $this->assertSame(96, Settings::get('cancellation_policy')['tiers'][0]['min_hours_before']);
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.updated'"));
        $res = $this->web('POST', '/admin/settings', ['csrf' => $csrf, 'fee_percent' => '95', 'dispute_window_hours' => '72'], $cookie);
        $this->assertSame(422, $res->status, 'out-of-range values refused');
    }

    public function testModeratorSuspendsUserAndHidesReportedMessage(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $msg] = $this->call('POST', "/api/bookings/{$b['id']}/messages", ['body' => 'Abusive text'], $m['token']);
        $this->call('POST', '/api/reports', ['target_type' => 'message', 'target_id' => $msg['id'], 'reason' => 'abuse'], $c['token']);
        [$cookie, $csrf] = $this->signIn('moderator');
        $rid = (int) Db::get()->value('SELECT id FROM reports');
        $this->web('POST', '/admin/reports', ['csrf' => $csrf, 'id' => $rid, 'action' => 'hide'], $cookie);
        [$s, $list] = $this->call('GET', "/api/bookings/{$b['id']}/messages", null, $c['token']);
        $this->assertTrue($list['items'][0]['hidden']);
        $this->assertNull($list['items'][0]['body']);
        $this->web('POST', "/admin/users/{$m['id']}", ['csrf' => $csrf, 'action' => 'suspend', 'reason' => 'Abuse'], $cookie);
        [$s] = $this->call('GET', '/api/me', null, $m['token']);
        $this->assertSame(401, $s, 'suspension ends existing sessions');
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.suspend'"));
    }

    public function testVerificationApprovalShowsBadge(): void
    {
        $m = $this->musician('verify@example.test');
        $this->call('POST', '/api/portfolio', ['kind' => 'video_link', 'url' => 'https://youtube.com/watch?v=x'], $m['token']);
        [$s] = $this->call('POST', '/api/verifications', ['kind' => 'portfolio'], $m['token']);
        $this->assertSame(200, $s);
        [$cookie, $csrf] = $this->signIn('moderator');
        $vid = (int) Db::get()->value('SELECT id FROM verifications');
        $this->web('POST', '/admin/verifications', ['csrf' => $csrf, 'id' => $vid, 'decision' => 'approve'], $cookie);
        [$s, $p] = $this->call('GET', "/api/musicians/{$m['id']}", null, $m['token']);
        $this->assertContains('portfolio', array_column($p['verifications'], 'kind'));
    }

    public function testFinanceRecordsPayout(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        Db::get()->insert('payouts', ['booking_id' => $b['id'], 'musician_id' => $m['id'], 'amount_minor' => 9000, 'currency' => 'USD', 'created_at' => Clock::db()]);
        [$cookie, $csrf] = $this->signIn('finance');
        $pid = (int) Db::get()->value('SELECT id FROM payouts');
        $this->web('POST', '/admin/payments', ['csrf' => $csrf, 'kind' => 'payout', 'id' => $pid, 'method' => 'ecocash', 'ref' => 'MP2610.1234'], $cookie);
        $this->assertSame('paid', Db::get()->value('SELECT status FROM payouts'));
        $this->web('POST', '/admin/payments', ['csrf' => $csrf, 'kind' => 'payout', 'id' => $pid, 'method' => 'ecocash', 'ref' => 'again'], $cookie);
        $this->assertSame('MP2610.1234', Db::get()->value('SELECT external_ref FROM payouts'), 'cannot be paid twice');
    }

    public function testPublicPagesAndSandboxCheckout(): void
    {
        foreach (['/', '/privacy', '/terms'] as $p) {
            $res = $this->web('GET', $p);
            $this->assertSame(200, $res->status, $p);
            $this->assertSame('noindex, nofollow', $res->headers['X-Robots-Tag'] ?? 'noindex, nofollow');
        }
        $this->assertStringContainsString('DRAFT FOR BUSINESS AND LEGAL REVIEW', $this->web('GET', '/privacy')->body);
        $this->assertStringContainsString('Disallow: /', $this->web('GET', '/robots.txt')->body);
        $this->assertSame(404, $this->web('GET', '/download/app.apk')->status, 'no APK uploaded yet');

        [$c, $m, $b] = $this->confirmedBooking();
        [$s, $p] = $this->call('POST', "/api/bookings/{$b['id']}/payments", [], $c['token']);
        $url = parse_url($p['checkout_url']);
        $this->assertSame(404, $this->web('GET', $url['path'] . '?sig=bad')->status, 'checkout links are signed');
        $page = $this->web('GET', $url['path'] . '?' . $url['query']);
        $this->assertStringContainsString('TEST PAYMENT', $page->body);
        $done = $this->web('POST', $url['path'] . '?' . $url['query'], ['outcome' => 'paid']);
        $this->assertStringContainsString('successful', $done->body);
        $this->assertSame('paid', Db::get()->value('SELECT status FROM payments WHERE id = ?', [$p['id']]));
    }
}
