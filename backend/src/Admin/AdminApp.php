<?php
declare(strict_types=1);

namespace Blissful\Admin;

use Blissful\Config;
use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Http\Request;
use Blissful\Http\Response;
use Blissful\Service\FileService;
use Blissful\Service\NotificationService;
use Blissful\Service\PaymentService;
use Blissful\Service\ReviewService;
use Blissful\Service\SafetyService;
use Blissful\Support\Audit;
use Blissful\Support\Clock;
use Blissful\Support\Crypto;
use Blissful\Support\Money;
use Blissful\Support\RateLimiter;
use Blissful\Support\Settings;
use Blissful\Support\Totp;

/**
 * Server-rendered staff dashboard at /admin.
 * Sign-in = password + mandatory TOTP. Separate HttpOnly/SameSite=Strict cookie session (bearer tokens never work here),
 * CSRF token on every POST, role-based permissions, and an audit record for every change.
 */
final class AdminApp
{
    private const COOKIE = 'bg_admin';
    private const SESSION_HOURS = 4;
    private const PERMS = [
        'superadmin' => ['*'],
        'moderator' => ['dashboard', 'users', 'users.write', 'verifications', 'reports', 'disputes', 'requests', 'bookings', 'reviews.write'],
        'support' => ['dashboard', 'users', 'disputes', 'requests', 'bookings', 'reports'],
        'finance' => ['dashboard', 'payments', 'payments.write', 'bookings'],
    ];

    private ?array $session = null;
    private ?array $staff = null;
    private string $nonce;

    public function __construct()
    {
        $this->nonce = Crypto::token(12);
    }

    public function handle(Request $q): Response
    {
        $path = substr($q->path, strlen('/admin')) ?: '/';
        if ($path === '/login') {
            return $q->method === 'POST' ? $this->doLogin($q) : $this->page('Sign in', $this->loginForm(), false);
        }
        $this->loadSession($q);
        if ($this->session === null) {
            return Response::redirect('/admin/login');
        }
        if ($q->method === 'POST' && !hash_equals($this->session['csrf_token'], (string) ($q->post['csrf'] ?? ''))) {
            return $this->page('Error', '<p class="err">Your form expired. Go back and try again.</p>', true, 400);
        }
        if ($path === '/logout' && $q->method === 'POST') {
            Db::get()->exec('DELETE FROM admin_sessions WHERE id = ?', [$this->session['id']]);
            return $this->withCookie(Response::redirect('/admin/login'), '', -3600);
        }
        if (!$this->session['totp_passed']) {
            return $this->totpGate($q, $path);
        }
        try {
            return match (true) {
                $path === '/' => $this->guard('dashboard', fn () => $this->dashboard()),
                $path === '/users' => $this->guard('users', fn () => $this->users($q)),
                (bool) preg_match('#^/users/(\d+)$#', $path, $m) => $this->guard('users', fn () => $this->user($q, (int) $m[1])),
                $path === '/verifications' => $this->guard('verifications', fn () => $this->verifications($q)),
                (bool) preg_match('#^/files/(\d+)$#', $path, $m) => $this->guard('verifications', fn () => $this->file((int) $m[1])),
                $path === '/requests' => $this->guard('requests', fn () => $this->requests($q)),
                $path === '/bookings' => $this->guard('bookings', fn () => $this->bookings($q)),
                (bool) preg_match('#^/bookings/(\d+)$#', $path, $m) => $this->guard('bookings', fn () => $this->booking((int) $m[1])),
                $path === '/disputes' => $this->guard('disputes', fn () => $this->disputes($q)),
                $path === '/reports' => $this->guard('reports', fn () => $this->reports($q)),
                $path === '/payments' => $this->guard('payments', fn () => $this->payments($q)),
                $path === '/catalog' => $this->guard('catalog', fn () => $this->catalog($q)),
                $path === '/settings' => $this->guard('settings', fn () => $this->settings($q)),
                $path === '/audit' => $this->guard('audit', fn () => $this->audit($q)),
                default => $this->page('Not found', '<p>Page not found.</p>', true, 404),
            };
        } catch (ApiError $e) {
            return $this->page('Error', '<p class="err">' . $this->h($e->getMessage()) . '</p><p><a href="javascript:history.back()">Back</a></p>', true, $e->status);
        }
    }

    // ------------------------------------------------------------------ auth

    private function doLogin(Request $q): Response
    {
        $email = mb_strtolower(trim((string) ($q->post['email'] ?? '')));
        $password = (string) ($q->post['password'] ?? '');
        if (!RateLimiter::hit('admin_login:ip:' . $q->ip, 10, 900) || !RateLimiter::hit('admin_login:email:' . $email, 5, 900)) {
            return $this->page('Sign in', $this->loginForm('Too many attempts. Wait 15 minutes.'), false, 429);
        }
        $user = Db::get()->one("SELECT u.*, s.role FROM users u JOIN staff s ON s.user_id = u.id WHERE u.email = ? AND u.status = 'active'", [$email]);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            Audit::record($user['id'] ?? null, 'admin.login_failed', null, null, [], $q->ip);
            return $this->page('Sign in', $this->loginForm('Email or password is incorrect.'), false, 401);
        }
        $sid = Crypto::token(32);
        Db::get()->insert('admin_sessions', ['id' => hash('sha256', $sid), 'user_id' => $user['id'], 'csrf_token' => bin2hex(random_bytes(32)),
            'totp_passed' => 0, 'ip' => $q->ip, 'expires_at' => Clock::db(Clock::now()->modify('+' . self::SESSION_HOURS . ' hours')), 'created_at' => Clock::db()]);
        Audit::record((int) $user['id'], 'admin.password_ok', null, null, [], $q->ip);
        return $this->withCookie(Response::redirect('/admin/'), $sid, self::SESSION_HOURS * 3600);
    }

    private function loadSession(Request $q): void
    {
        $cookie = $q->header('cookie') ?? '';
        if (!preg_match('/(?:^|;\s*)' . self::COOKIE . '=([A-Za-z0-9_\-]{20,100})/', $cookie, $m)) {
            return;
        }
        $s = Db::get()->one('SELECT a.*, s.role, s.totp_enabled, s.totp_secret_enc, s.last_totp_step, u.email, u.display_name
            FROM admin_sessions a JOIN staff s ON s.user_id = a.user_id JOIN users u ON u.id = a.user_id
            WHERE a.id = ? AND a.expires_at > ? AND u.status = ?', [hash('sha256', $m[1]), Clock::db(), 'active']);
        if ($s !== null) {
            $this->session = $s;
            $this->staff = $s;
        }
    }

    private function totpGate(Request $q, string $path): Response
    {
        $db = Db::get();
        $enrolled = (bool) $this->session['totp_enabled'];
        $secret = null;
        if ($enrolled) {
            $secret = Crypto::decrypt((string) $this->session['totp_secret_enc']);
        } else {
            if ($this->session['totp_secret_enc'] === null) {
                $db->exec('UPDATE staff SET totp_secret_enc = ? WHERE user_id = ?', [Crypto::encrypt(Totp::newSecret()), $this->session['user_id']]);
                $this->session['totp_secret_enc'] = $db->value('SELECT totp_secret_enc FROM staff WHERE user_id = ?', [$this->session['user_id']]);
            }
            $secret = Crypto::decrypt((string) $this->session['totp_secret_enc']);
        }
        $error = '';
        if ($q->method === 'POST' && $path === '/totp') {
            if (!RateLimiter::hit('admin_totp:' . $this->session['user_id'], 6, 300)) {
                $error = 'Too many attempts. Wait 5 minutes.';
            } else {
                $step = Totp::verify((string) $secret, trim((string) ($q->post['code'] ?? '')), $this->session['last_totp_step'] !== null ? (int) $this->session['last_totp_step'] : null);
                if ($step !== null) {
                    $db->exec('UPDATE staff SET totp_enabled = 1, last_totp_step = ? WHERE user_id = ?', [$step, $this->session['user_id']]);
                    $db->exec('UPDATE admin_sessions SET totp_passed = 1 WHERE id = ?', [$this->session['id']]);
                    Audit::record((int) $this->session['user_id'], $enrolled ? 'admin.login' : 'admin.totp_enrolled', null, null, [], $q->ip);
                    return Response::redirect('/admin/');
                }
                $error = 'That code is not valid. Check your phone clock and try again.';
            }
        }
        $body = $enrolled ? '<p>Enter the 6-digit code from your authenticator app.</p>'
            : '<p><strong>Set up two-step verification.</strong> In Google Authenticator, Microsoft Authenticator or Authy, add an account with this key:</p>'
              . '<p class="mono big">' . $this->h(trim(chunk_split((string) $secret, 4, ' '))) . '</p>'
              . '<p class="muted small">Or use this setup link: <span class="mono">' . $this->h(Totp::uri((string) $secret, $this->session['email'])) . '</span></p>';
        $body .= ($error !== '' ? '<p class="err">' . $this->h($error) . '</p>' : '')
            . '<form method="post" action="/admin/totp">' . $this->csrf()
            . '<label>Code <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus></label>'
            . '<button>Verify</button></form>';
        return $this->page('Two-step verification', $body, false);
    }

    private function guard(string $perm, callable $fn): Response
    {
        if (!$this->can($perm)) {
            return $this->page('Not allowed', '<p class="err">Your role (' . $this->h($this->staff['role']) . ') cannot open this page.</p>', true, 403);
        }
        return $fn();
    }

    private function can(string $perm): bool
    {
        $p = self::PERMS[$this->staff['role']] ?? [];
        return in_array('*', $p, true) || in_array($perm, $p, true);
    }

    private function audit(Request $q): Response
    {
        $rows = Db::get()->all('SELECT a.*, u.email FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 200');
        return $this->page('Audit log', $this->table(['When (UTC)', 'Actor', 'Action', 'Target', 'IP', 'Details'], array_map(fn ($r) => [
            $r['created_at'], $r['email'] ?? 'system', $r['action'], $r['target_type'] ? $r['target_type'] . ' #' . $r['target_id'] : '', $r['ip'] ?? '', $r['meta_json'] ?? '',
        ], $rows)));
    }

    // ------------------------------------------------------------------ pages

    private function dashboard(): Response
    {
        $db = Db::get();
        $since = Clock::db(Clock::now()->modify('-30 days'));
        $stats = [
            'Active users' => $db->value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'Musicians' => $db->value('SELECT COUNT(*) FROM musician_profiles'),
            'Open requests' => $db->value("SELECT COUNT(*) FROM requests WHERE status = 'open'"),
            'Requests (30 days)' => $db->value('SELECT COUNT(*) FROM requests WHERE created_at >= ?', [$since]),
            'Offers (30 days)' => $db->value('SELECT COUNT(*) FROM offers WHERE created_at >= ?', [$since]),
            'Bookings (30 days)' => $db->value('SELECT COUNT(*) FROM bookings WHERE created_at >= ?', [$since]),
            'Completed (30 days)' => $db->value("SELECT COUNT(*) FROM bookings WHERE status = 'completed' AND completed_at >= ?", [$since]),
            'Cancelled (30 days)' => $db->value("SELECT COUNT(*) FROM bookings WHERE status = 'cancelled' AND updated_at >= ?", [$since]),
            'Open disputes' => $db->value("SELECT COUNT(*) FROM disputes WHERE status <> 'resolved'"),
            'Open reports' => $db->value("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
            'Pending verifications' => $db->value("SELECT COUNT(*) FROM verifications WHERE status = 'pending'"),
            'Paid volume (30 days)' => Money::format((int) $db->value("SELECT COALESCE(SUM(amount_minor),0) FROM payments WHERE status IN ('paid','partially_refunded') AND paid_at >= ?", [$since]), 'USD'),
            'Platform fees (30 days)' => Money::format((int) $db->value("SELECT COALESCE(SUM(b.fee_minor),0) FROM bookings b WHERE b.status = 'completed' AND b.completed_at >= ?", [$since]), 'USD'),
        ];
        $avgOffers = $db->value("SELECT ROUND(AVG(c), 1) FROM (SELECT COUNT(o.id) c FROM requests r LEFT JOIN offers o ON o.request_id = r.id WHERE r.published_at >= ? GROUP BY r.id) t", [$since]);
        $stats['Avg offers per request'] = $avgOffers ?? '—';
        $html = '<div class="grid">';
        foreach ($stats as $k => $v) {
            $html .= '<div class="stat"><div class="muted small">' . $this->h($k) . '</div><div class="big">' . $this->h((string) $v) . '</div></div>';
        }
        $html .= '</div>';
        $top = $db->all("SELECT i.name, COUNT(*) c FROM request_positions p JOIN instruments i ON i.id = p.instrument_id JOIN requests r ON r.id = p.request_id
                         WHERE r.created_at >= ? GROUP BY i.name ORDER BY c DESC LIMIT 8", [$since]);
        $html .= '<h2>Most requested instruments (30 days)</h2>' . $this->table(['Instrument', 'Positions'], array_map(fn ($r) => [$r['name'], $r['c']], $top));
        return $this->page('Overview', $html);
    }

    private function users(Request $q): Response
    {
        $term = trim((string) ($q->query['q'] ?? ''));
        $params = [];
        $sql = 'SELECT u.*, s.role FROM users u LEFT JOIN staff s ON s.user_id = u.id';
        if ($term !== '') {
            $sql .= ' WHERE u.email LIKE ? OR u.display_name LIKE ?';
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $params = [$like, $like];
        }
        $rows = Db::get()->all($sql . ' ORDER BY u.id DESC LIMIT 100', $params);
        $html = '<form method="get"><input name="q" value="' . $this->h($term) . '" placeholder="Search email or name"><button>Search</button></form>';
        $html .= $this->table(['ID', 'Name', 'Email', 'Roles', 'Status', 'Joined'], array_map(fn ($u) => [
            '<a href="/admin/users/' . (int) $u['id'] . '">#' . (int) $u['id'] . '</a>', $this->h($u['display_name']), $this->h($u['email']),
            $this->h(implode(', ', array_filter(['client', $u['is_musician'] ? 'musician' : null, $u['role'] ? 'staff:' . $u['role'] : null]))),
            $this->h($u['status']), $this->h($u['created_at']),
        ], $rows), true);
        return $this->page('Users', $html);
    }

    private function user(Request $q, int $id): Response
    {
        $db = Db::get();
        $u = $db->one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($u === null) {
            throw ApiError::notFound('User');
        }
        if ($q->method === 'POST') {
            if (!$this->can('users.write')) {
                throw ApiError::forbidden();
            }
            $action = (string) ($q->post['action'] ?? '');
            if ($db->value('SELECT 1 FROM staff WHERE user_id = ?', [$id]) && $this->staff['role'] !== 'superadmin') {
                throw ApiError::forbidden('Only a superadmin can change staff accounts.');
            }
            if ($action === 'suspend' || $action === 'reactivate') {
                $db->exec('UPDATE users SET status = ?, updated_at = ? WHERE id = ? AND status <> ?', [$action === 'suspend' ? 'suspended' : 'active', Clock::db(), $id, 'deleted']);
                if ($action === 'suspend') {
                    $db->exec('UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Clock::db(), $id]);
                }
            } elseif ($action === 'revoke_sessions') {
                $db->exec('UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Clock::db(), $id]);
            } else {
                throw ApiError::validation(['action' => 'Unknown action']);
            }
            Audit::record((int) $this->staff['user_id'], 'user.' . $action, 'user', $id, ['reason' => mb_substr((string) ($q->post['reason'] ?? ''), 0, 300)], $q->ip);
            return Response::redirect('/admin/users/' . $id);
        }
        $mp = $db->one('SELECT * FROM musician_profiles WHERE user_id = ?', [$id]);
        $cp = $db->one('SELECT * FROM client_profiles WHERE user_id = ?', [$id]);
        $html = '<dl>'
            . $this->dl('Name', $u['display_name']) . $this->dl('Email', $u['email']) . $this->dl('Phone', (string) $u['phone'])
            . $this->dl('Status', $u['status']) . $this->dl('Email verified', $u['email_verified_at'] ? 'yes' : 'no')
            . $this->dl('Client', ($cp['name'] ?? '') . ' (' . ($cp['org_type'] ?? '') . ')')
            . ($mp ? $this->dl('Musician', $mp['stage_name'] . ' · ' . $mp['city'] . ' · ' . $mp['completed_count'] . ' completed') : '')
            . '</dl>';
        if ($this->can('users.write')) {
            $html .= '<form method="post" class="inline">' . $this->csrf() . '<input name="reason" placeholder="Reason (kept in audit log)" required>'
                . ($u['status'] === 'active' ? '<button name="action" value="suspend" class="danger">Suspend</button>' : '<button name="action" value="reactivate">Reactivate</button>')
                . '<button name="action" value="revoke_sessions">Sign out everywhere</button></form>';
        }
        $bookings = $db->all('SELECT id, status, starts_at, total_minor, currency FROM bookings WHERE client_id = ? OR musician_id = ? ORDER BY id DESC LIMIT 20', [$id, $id]);
        $html .= '<h2>Bookings</h2>' . $this->table(['Booking', 'Status', 'Starts (UTC)', 'Total'], array_map(fn ($b) => [
            '<a href="/admin/bookings/' . (int) $b['id'] . '">#' . (int) $b['id'] . '</a>', $this->h($b['status']), $this->h($b['starts_at']),
            $this->h(Money::format((int) $b['total_minor'], $b['currency'])),
        ], $bookings), true);
        return $this->page('User #' . $id, $html);
    }

    private function verifications(Request $q): Response
    {
        $db = Db::get();
        if ($q->method === 'POST') {
            $vid = (int) ($q->post['id'] ?? 0);
            $decision = ($q->post['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
            $v = $db->one("SELECT * FROM verifications WHERE id = ? AND status = 'pending'", [$vid]);
            if ($v === null) {
                throw ApiError::conflict('done', 'Already reviewed.');
            }
            $db->exec('UPDATE verifications SET status = ?, reviewer_id = ?, reviewer_note = ?, reviewed_at = ? WHERE id = ?',
                [$decision, $this->staff['user_id'], mb_substr((string) ($q->post['note'] ?? ''), 0, 500), Clock::db(), $vid]);
            if ($v['document_file_id'] !== null) {
                // Documents are only needed for the review itself; shorten retention to 7 days after decision.
                $db->exec('UPDATE files SET retain_until = ? WHERE id = ?', [Clock::db(Clock::now()->modify('+7 days')), $v['document_file_id']]);
            }
            NotificationService::send((int) $v['user_id'], 'verification', $decision === 'approved' ? 'Verification approved' : 'Verification not approved',
                $decision === 'approved' ? 'A badge now shows on your profile.' : 'Open the app for details or contact support.', [], "verification:{$vid}");
            Audit::record((int) $this->staff['user_id'], 'verification.' . $decision, 'verification', $vid, [], $q->ip);
            return Response::redirect('/admin/verifications');
        }
        $rows = $db->all("SELECT v.*, u.display_name, u.email FROM verifications v JOIN users u ON u.id = v.user_id WHERE v.status = 'pending' ORDER BY v.id LIMIT 100");
        $html = '<p class="muted">Approve only what you actually checked. "identity" = government ID matches the profile name. "portfolio" = you reviewed the samples.</p>';
        $html .= $this->table(['ID', 'User', 'Check', 'Document', 'Decision'], array_map(fn ($v) => [
            (int) $v['id'], '<a href="/admin/users/' . (int) $v['user_id'] . '">' . $this->h($v['display_name']) . '</a>', $this->h($v['kind']),
            $v['document_file_id'] ? '<a href="/admin/files/' . (int) $v['document_file_id'] . '" target="_blank" rel="noopener">View (audited)</a>' : '—',
            '<form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="id" value="' . (int) $v['id'] . '"><input name="note" placeholder="Note">'
            . '<button name="decision" value="approve">Approve</button><button name="decision" value="reject" class="danger">Reject</button></form>',
        ], $rows), true);
        return $this->page('Verification queue', $html);
    }

    private function file(int $id): Response
    {
        $f = (new FileService())->resolveForDownload($id, null, true);
        if ($f === null) {
            throw ApiError::notFound('File');
        }
        Audit::record((int) $this->staff['user_id'], 'file.viewed', 'file', $id);
        return new Response(200, (string) file_get_contents($f['path']), ['Content-Type' => $f['mime'], 'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'"]);
    }

    private function requests(Request $q): Response
    {
        $status = (string) ($q->query['status'] ?? 'open');
        $rows = Db::get()->all('SELECT r.*, c.name FROM requests r JOIN client_profiles c ON c.user_id = r.client_id WHERE r.status = ? ORDER BY r.id DESC LIMIT 100',
            [in_array($status, ['draft', 'open', 'filled', 'closed', 'expired', 'cancelled'], true) ? $status : 'open']);
        $html = $this->filter('status', ['open', 'filled', 'closed', 'expired', 'cancelled', 'draft'], $status);
        $html .= $this->table(['ID', 'Title', 'Client', 'City', 'Starts (UTC)', 'Deadline (UTC)', 'Offers'], array_map(fn ($r) => [
            (int) $r['id'], $this->h($r['title']), $this->h($r['name']), $this->h($r['city']), $this->h($r['starts_at']), $this->h($r['offer_deadline']),
            (int) Db::get()->value('SELECT COUNT(*) FROM offers WHERE request_id = ?', [$r['id']]),
        ], $rows), true);
        return $this->page('Requests', $html);
    }

    private function bookings(Request $q): Response
    {
        $status = (string) ($q->query['status'] ?? 'confirmed');
        $rows = Db::get()->all('SELECT b.*, r.title FROM bookings b JOIN requests r ON r.id = b.request_id WHERE b.status = ? ORDER BY b.starts_at DESC LIMIT 100',
            [in_array($status, ['confirmed', 'in_progress', 'completed', 'cancelled', 'disputed'], true) ? $status : 'confirmed']);
        $html = $this->filter('status', ['confirmed', 'in_progress', 'completed', 'cancelled', 'disputed'], $status);
        $html .= $this->table(['Booking', 'Event', 'Starts (UTC)', 'Total', 'Fee'], array_map(fn ($b) => [
            '<a href="/admin/bookings/' . (int) $b['id'] . '">#' . (int) $b['id'] . '</a>', $this->h($b['title']), $this->h($b['starts_at']),
            $this->h(Money::format((int) $b['total_minor'], $b['currency'])), $this->h(Money::format((int) $b['fee_minor'], $b['currency'])),
        ], $rows), true);
        return $this->page('Bookings', $html);
    }

    private function booking(int $id): Response
    {
        $db = Db::get();
        $b = $db->one('SELECT b.*, r.title, c.name AS client, m.stage_name FROM bookings b JOIN requests r ON r.id = b.request_id
            JOIN client_profiles c ON c.user_id = b.client_id JOIN musician_profiles m ON m.user_id = b.musician_id WHERE b.id = ?', [$id]);
        if ($b === null) {
            throw ApiError::notFound('Booking');
        }
        $html = '<dl>' . $this->dl('Event', $b['title']) . $this->dl('Status', $b['status'])
            . $this->dl('Client', $b['client'] . ' (#' . $b['client_id'] . ')') . $this->dl('Musician', $b['stage_name'] . ' (#' . $b['musician_id'] . ')')
            . $this->dl('Starts', $b['starts_at'] . ' UTC (' . $b['timezone'] . ')') . $this->dl('Total', Money::format((int) $b['total_minor'], $b['currency']))
            . $this->dl('Cancel reason', (string) $b['cancel_reason']) . '</dl>';
        $html .= '<h2>Timeline</h2>' . $this->table(['When (UTC)', 'Event', 'Actor', 'Detail'], array_map(fn ($e) => [
            $this->h($e['created_at']), $this->h($e['event']), $this->h((string) ($e['actor_id'] ?? 'system')), $this->h((string) $e['detail_json']),
        ], $db->all('SELECT * FROM booking_events WHERE booking_id = ? ORDER BY id', [$id])), true);
        $html .= '<h2>Payments</h2>' . $this->table(['Ref', 'Mode', 'Status', 'Amount', 'Refunded'], array_map(fn ($p) => [
            $this->h($p['reference']), $this->h($p['mode']), $this->h($p['status']), $this->h(Money::format((int) $p['amount_minor'], $p['currency'])),
            $this->h(Money::format((int) $p['refunded_minor'], $p['currency'])),
        ], $db->all('SELECT * FROM payments WHERE booking_id = ? ORDER BY id', [$id])), true);
        $html .= '<p class="muted small">Chat content is private to participants. If a message is reported, it appears in the Reports queue.</p>';
        return $this->page('Booking #' . $id, $html);
    }

    private function disputes(Request $q): Response
    {
        $db = Db::get();
        if ($q->method === 'POST') {
            $outcome = ($q->post['outcome'] ?? '') === 'cancel' ? 'cancel' : 'complete';
            $refund = \Blissful\Support\Money::parseDecimal(trim((string) ($q->post['refund'] ?? '0')), 'USD');
            $resolution = trim((string) ($q->post['resolution'] ?? ''));
            if ($refund === null || mb_strlen($resolution) < 10) {
                throw ApiError::validation(['resolution' => 'Write a resolution (10+ characters) and a valid refund amount, e.g. 25.00']);
            }
            SafetyService::resolveDispute((int) $this->staff['user_id'], (int) ($q->post['id'] ?? 0), $outcome, $resolution, $refund);
            return Response::redirect('/admin/disputes');
        }
        $rows = $db->all("SELECT d.*, b.total_minor, b.currency, u.display_name FROM disputes d JOIN bookings b ON b.id = d.booking_id
            JOIN users u ON u.id = d.opened_by WHERE d.status <> 'resolved' ORDER BY d.id LIMIT 100");
        $html = $this->table(['Dispute', 'Booking', 'Opened by', 'Reason', 'Details', 'Resolve'], array_map(fn ($d) => [
            (int) $d['id'], '<a href="/admin/bookings/' . (int) $d['booking_id'] . '">#' . (int) $d['booking_id'] . '</a>', $this->h($d['display_name']),
            $this->h($d['reason']), $this->h($d['details']),
            '<form method="post">' . $this->csrf() . '<input type="hidden" name="id" value="' . (int) $d['id'] . '">'
            . '<textarea name="resolution" placeholder="Decision shared with both parties" required></textarea>'
            . '<label>Refund to client <input name="refund" value="0.00" size="8"> of ' . $this->h(Money::format((int) $d['total_minor'], $d['currency'])) . '</label>'
            . '<button name="outcome" value="complete">Complete booking</button><button name="outcome" value="cancel" class="danger">Cancel &amp; refund</button></form>',
        ], $rows), true);
        return $this->page('Disputes', $html);
    }

    private function reports(Request $q): Response
    {
        $db = Db::get();
        if ($q->method === 'POST') {
            if (!$this->can('users.write')) {
                throw ApiError::forbidden();
            }
            $rid = (int) ($q->post['id'] ?? 0);
            $r = $db->one("SELECT * FROM reports WHERE id = ? AND status = 'open'", [$rid]);
            if ($r === null) {
                throw ApiError::conflict('done', 'Already handled.');
            }
            $action = (string) ($q->post['action'] ?? 'dismiss');
            if ($action === 'hide') {
                if ($r['target_type'] === 'message') {
                    $db->exec('UPDATE messages SET is_hidden = 1 WHERE id = ?', [$r['target_id']]);
                } elseif ($r['target_type'] === 'review') {
                    ReviewService::setHidden((int) $r['target_id'], true);
                }
            }
            $db->exec('UPDATE reports SET status = ?, handled_by = ?, handled_at = ?, handler_note = ? WHERE id = ?',
                [$action === 'dismiss' ? 'dismissed' : 'actioned', $this->staff['user_id'], Clock::db(), mb_substr((string) ($q->post['note'] ?? ''), 0, 500), $rid]);
            Audit::record((int) $this->staff['user_id'], 'report.' . $action, 'report', $rid, ['target' => $r['target_type'] . '#' . $r['target_id']], $q->ip);
            return Response::redirect('/admin/reports');
        }
        $rows = $db->all("SELECT r.*, u.display_name FROM reports r JOIN users u ON u.id = r.reporter_id WHERE r.status = 'open' ORDER BY r.id LIMIT 100");
        $html = $this->table(['Report', 'By', 'Target', 'Content', 'Reason', 'Action'], array_map(function ($r) use ($db) {
            $content = match ($r['target_type']) {
                'message' => (string) $db->value('SELECT body FROM messages WHERE id = ?', [$r['target_id']]),
                'review' => (string) $db->value('SELECT comment FROM reviews WHERE id = ?', [$r['target_id']]),
                'user' => '<a href="/admin/users/' . (int) $r['target_id'] . '">User #' . (int) $r['target_id'] . '</a>',
                default => '',
            };
            return [(int) $r['id'], $this->h($r['display_name']), $this->h($r['target_type'] . ' #' . $r['target_id']),
                $r['target_type'] === 'user' ? $content : $this->h(mb_substr($content, 0, 300)), $this->h($r['reason'] . ($r['details'] ? ': ' . $r['details'] : '')),
                $this->can('users.write') ? '<form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '">'
                    . (in_array($r['target_type'], ['message', 'review'], true) ? '<button name="action" value="hide" class="danger">Hide content</button>' : '')
                    . '<button name="action" value="actioned">Mark actioned</button><button name="action" value="dismiss">Dismiss</button></form>' : 'view only'];
        }, $rows), true);
        return $this->page('Reports', $html);
    }

    private function payments(Request $q): Response
    {
        $db = Db::get();
        if ($q->method === 'POST') {
            if (!$this->can('payments.write')) {
                throw ApiError::forbidden();
            }
            $kind = (string) ($q->post['kind'] ?? '');
            $id = (int) ($q->post['id'] ?? 0);
            if ($kind === 'refund') {
                $db->tx(fn (Db $db) => PaymentService::completeRefund($db, $id, (int) $this->staff['user_id']));
            } elseif ($kind === 'payout') {
                $ref = trim((string) ($q->post['ref'] ?? ''));
                if ($ref === '') {
                    throw ApiError::validation(['ref' => 'Enter the transfer reference.']);
                }
                $n = $db->exec("UPDATE payouts SET status = 'paid', method = ?, external_ref = ?, paid_by = ?, paid_at = ? WHERE id = ? AND status = 'pending'",
                    [mb_substr((string) ($q->post['method'] ?? 'ecocash'), 0, 40), mb_substr($ref, 0, 100), $this->staff['user_id'], Clock::db(), $id]);
                if ($n === 1) {
                    Audit::record((int) $this->staff['user_id'], 'payout.paid', 'payout', $id, ['ref' => $ref], $q->ip);
                }
            }
            return Response::redirect('/admin/payments');
        }
        $html = '<p class="muted">Paynow does not provide refund or payout APIs to this platform; complete them in the Paynow merchant portal or by mobile money, then record them here.</p>';
        $html .= '<h2>Refunds to process</h2>' . $this->table(['Refund', 'Payment', 'Amount', 'Method', 'Action'], array_map(fn ($r) => [
            (int) $r['id'], $this->h($r['reference']), $this->h(Money::format((int) $r['amount_minor'], $r['currency'])), $this->h($r['method']),
            '<form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="kind" value="refund"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button>Mark refunded</button></form>',
        ], $db->all("SELECT f.*, p.reference, p.currency FROM refunds f JOIN payments p ON p.id = f.payment_id WHERE f.status = 'pending' ORDER BY f.id")), true);
        $html .= '<h2>Musician payouts due</h2>' . $this->table(['Payout', 'Booking', 'Musician', 'Amount', 'Action'], array_map(fn ($p) => [
            (int) $p['id'], '<a href="/admin/bookings/' . (int) $p['booking_id'] . '">#' . (int) $p['booking_id'] . '</a>', $this->h($p['stage_name']),
            $this->h(Money::format((int) $p['amount_minor'], $p['currency'])),
            '<form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="kind" value="payout"><input type="hidden" name="id" value="' . (int) $p['id'] . '">'
            . '<select name="method"><option>ecocash</option><option>bank</option><option>onemoney</option><option>cash</option></select><input name="ref" placeholder="Reference" required><button>Mark paid</button></form>',
        ], $db->all("SELECT p.*, m.stage_name FROM payouts p JOIN musician_profiles m ON m.user_id = p.musician_id WHERE p.status = 'pending' ORDER BY p.id")), true);
        $html .= '<h2>Recent payments</h2>' . $this->table(['ID', 'Booking', 'Ref', 'Mode', 'Status', 'Amount', 'Created (UTC)'], array_map(fn ($p) => [
            (int) $p['id'], '#' . (int) $p['booking_id'], $this->h($p['reference']), $this->h($p['mode']), $this->h($p['status']),
            $this->h(Money::format((int) $p['amount_minor'], $p['currency'])), $this->h($p['created_at']),
        ], $db->all('SELECT * FROM payments ORDER BY id DESC LIMIT 50')), true);
        return $this->page('Payments', $html);
    }

    private function catalog(Request $q): Response
    {
        $db = Db::get();
        if ($q->method === 'POST') {
            $table = ($q->post['table'] ?? '') === 'genres' ? 'genres' : 'instruments';
            if (($q->post['action'] ?? '') === 'add') {
                $name = trim((string) ($q->post['name'] ?? ''));
                $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
                if (mb_strlen($name) < 2 || $slug === '') {
                    throw ApiError::validation(['name' => 'Name is too short.']);
                }
                $db->exec("INSERT IGNORE INTO {$table} (slug, name) VALUES (?, ?)", [$slug, mb_substr($name, 0, 80)]);
            } else {
                $db->exec("UPDATE {$table} SET is_active = 1 - is_active WHERE id = ?", [(int) ($q->post['id'] ?? 0)]);
            }
            Audit::record((int) $this->staff['user_id'], 'catalog.' . ($q->post['action'] ?? 'toggle'), $table, (int) ($q->post['id'] ?? 0), ['name' => $q->post['name'] ?? null], $q->ip);
            return Response::redirect('/admin/catalog');
        }
        $html = '';
        foreach (['instruments' => 'Instruments & services', 'genres' => 'Genres'] as $t => $label) {
            $html .= '<h2>' . $label . '</h2><form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="table" value="' . $t . '"><input name="name" placeholder="New ' . rtrim($t, 's') . '" required><button name="action" value="add">Add</button></form>';
            $html .= $this->table(['Name', 'Active', ''], array_map(fn ($r) => [$this->h($r['name']), $r['is_active'] ? 'yes' : 'no',
                '<form method="post" class="inline">' . $this->csrf() . '<input type="hidden" name="table" value="' . $t . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button name="action" value="toggle">' . ($r['is_active'] ? 'Disable' : 'Enable') . '</button></form>',
            ], $db->all("SELECT * FROM {$t} ORDER BY name")), true);
        }
        return $this->page('Catalogue', $html);
    }

    private function settings(Request $q): Response
    {
        if ($q->method === 'POST') {
            $fee = filter_var($q->post['fee_percent'] ?? '', FILTER_VALIDATE_FLOAT);
            $window = filter_var($q->post['dispute_window_hours'] ?? '', FILTER_VALIDATE_INT);
            $tiers = [];
            foreach ([0, 1, 2] as $i) {
                $h = filter_var($q->post["tier_hours_{$i}"] ?? '', FILTER_VALIDATE_INT);
                $pct = filter_var($q->post["tier_pct_{$i}"] ?? '', FILTER_VALIDATE_INT);
                if ($h !== false && $pct !== false && $h >= 0 && $pct >= 0 && $pct <= 100) {
                    $tiers[] = ['min_hours_before' => $h, 'charge_bps' => $pct * 100];
                }
            }
            if ($fee === false || $fee < 0 || $fee > 30 || $window === false || $window < 0 || $window > 720 || count($tiers) !== 3) {
                throw ApiError::validation(['settings' => 'Fee must be 0–30%, window 0–720 hours, and all three tiers filled (0–100%).']);
            }
            $before = ['fee' => Settings::get('platform_fee_bps'), 'policy' => Settings::get('cancellation_policy'), 'window' => Settings::get('dispute_window_hours')];
            Settings::set('platform_fee_bps', (int) round($fee * 100), (int) $this->staff['user_id']);
            Settings::set('dispute_window_hours', $window, (int) $this->staff['user_id']);
            Settings::set('cancellation_policy', ['tiers' => $tiers, 'musician_cancel_charge_bps' => 0], (int) $this->staff['user_id']);
            Audit::record((int) $this->staff['user_id'], 'settings.updated', 'settings', null, ['before' => $before], $q->ip);
            return Response::redirect('/admin/settings');
        }
        $policy = Settings::get('cancellation_policy');
        $html = '<form method="post" class="stack">' . $this->csrf()
            . '<label>Client service fee (%) <input name="fee_percent" value="' . $this->h(number_format(Settings::int('platform_fee_bps', 1000) / 100, 2)) . '"></label>'
            . '<label>Dispute window after completion (hours) <input name="dispute_window_hours" value="' . Settings::int('dispute_window_hours', 48) . '"></label>'
            . '<fieldset><legend>Client cancellation charge (applies to new bookings only)</legend>';
        foreach (array_values($policy['tiers'] ?? []) as $i => $t) {
            $html .= '<div>If cancelled at least <input name="tier_hours_' . $i . '" value="' . (int) $t['min_hours_before'] . '" size="4"> hours before start, charge <input name="tier_pct_' . $i . '" value="' . intdiv((int) $t['charge_bps'], 100) . '" size="4">%</div>';
        }
        $html .= '<p class="muted small">Musician cancellations never charge the client.</p></fieldset><button>Save settings</button></form>';
        return $this->page('Platform settings', $html);
    }

    // ------------------------------------------------------------------ rendering

    private function page(string $title, string $body, bool $nav = true, int $status = 200): Response
    {
        $env = Config::env();
        $banner = $env === 'production' ? '' : '<div class="banner">TESTING ENVIRONMENT · ' . $this->h(strtoupper($env)) . ' · no real customer data</div>';
        $links = '';
        if ($nav && $this->staff !== null) {
            foreach (['/' => ['Overview', 'dashboard'], '/users' => ['Users', 'users'], '/verifications' => ['Verification', 'verifications'],
                         '/requests' => ['Requests', 'requests'], '/bookings' => ['Bookings', 'bookings'], '/disputes' => ['Disputes', 'disputes'],
                         '/reports' => ['Reports', 'reports'], '/payments' => ['Payments', 'payments'], '/catalog' => ['Catalogue', 'catalog'],
                         '/settings' => ['Settings', 'settings'], '/audit' => ['Audit log', 'audit']] as $href => [$label, $perm]) {
                if ($this->can($perm)) {
                    $links .= '<a href="/admin' . ($href === '/' ? '/' : $href) . '">' . $label . '</a>';
                }
            }
            $links .= '<form method="post" action="/admin/logout" class="inline">' . $this->csrf() . '<button class="link">Sign out (' . $this->h($this->staff['email']) . ')</button></form>';
        }
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . $this->h($title) . ' · Blissful Gigs Admin</title><style nonce="' . $this->nonce . '">'
            . ':root{--plum:#3b1f4a;--gold:#e0a526;--ink:#1d1a20;--muted:#6b6470;--line:#e5e0e8;--bg:#faf8fb;--err:#b3261e}'
            . 'body{margin:0;font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif;color:var(--ink);background:var(--bg)}'
            . '.banner{background:var(--gold);color:#1d1a20;text-align:center;font-weight:700;padding:4px;font-size:13px}'
            . 'header{background:var(--plum);color:#fff;padding:10px 16px;display:flex;flex-wrap:wrap;gap:12px;align-items:center}header strong{margin-right:12px}'
            . 'header a,header .link{color:#fff;text-decoration:none;opacity:.9;background:none;border:0;font:inherit;cursor:pointer;padding:0}header a:hover{opacity:1;text-decoration:underline}'
            . 'main{max-width:1200px;margin:0 auto;padding:16px}h1{font-size:22px}h2{font-size:17px;margin-top:28px}'
            . 'table{width:100%;border-collapse:collapse;background:#fff;font-size:14px}th,td{border-bottom:1px solid var(--line);padding:8px;text-align:left;vertical-align:top}'
            . 'th{background:#f2edf4}input,select,textarea{font:inherit;padding:6px 8px;border:1px solid #c9c0cf;border-radius:6px}textarea{width:100%;min-height:60px}'
            . 'button{font:inherit;padding:6px 12px;border-radius:6px;border:0;background:var(--plum);color:#fff;cursor:pointer;margin:2px}button.danger{background:var(--err)}'
            . '.inline{display:inline-flex;gap:6px;flex-wrap:wrap;align-items:center}.stack label{display:block;margin:10px 0}.muted{color:var(--muted)}.small{font-size:13px}'
            . '.err{color:var(--err);font-weight:600}.mono{font-family:ui-monospace,monospace;word-break:break-all}.big{font-size:22px;font-weight:700}'
            . '.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}.stat{background:#fff;border:1px solid var(--line);border-radius:10px;padding:12px}'
            . 'dl{display:grid;grid-template-columns:160px 1fr;gap:4px 12px}dt{color:var(--muted)}.card{max-width:420px;margin:40px auto;background:#fff;padding:24px;border-radius:12px;border:1px solid var(--line)}'
            . '.card label{display:block;margin:12px 0}.card input{width:100%;box-sizing:border-box}.scroll{overflow-x:auto}'
            . '</style></head><body>' . $banner . '<header><strong>Blissful Gigs · Admin</strong>' . $links . '</header>'
            . '<main>' . ($nav ? '<h1>' . $this->h($title) . '</h1>' . $body : '<div class="card"><h1>' . $this->h($title) . '</h1>' . $body . '</div>') . '</main></body></html>';
        $r = Response::html($html, $status);
        $r->headers['Content-Security-Policy'] = "default-src 'none'; style-src 'nonce-{$this->nonce}'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";
        $r->headers['Cache-Control'] = 'no-store';
        return $r;
    }

    private function loginForm(string $error = ''): string
    {
        return ($error ? '<p class="err">' . $this->h($error) . '</p>' : '')
            . '<form method="post" action="/admin/login"><label>Email <input type="email" name="email" autocomplete="username" required></label>'
            . '<label>Password <input type="password" name="password" autocomplete="current-password" required></label><button>Continue</button></form>'
            . '<p class="muted small">Staff only. Every sign-in requires an authenticator code and is logged.</p>';
    }

    private function withCookie(Response $r, string $value, int $ttl): Response
    {
        $secure = str_starts_with((string) Config::get('APP_URL', ''), 'https://');
        $r->headers['Set-Cookie'] = self::COOKIE . '=' . $value . '; Path=/admin; Max-Age=' . $ttl . '; HttpOnly; SameSite=Strict' . ($secure ? '; Secure' : '');
        return $r;
    }

    private function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="' . $this->h((string) ($this->session['csrf_token'] ?? '')) . '">';
    }

    private function table(array $head, array $rows, bool $raw = false): string
    {
        if ($rows === []) {
            return '<p class="muted">Nothing here.</p>';
        }
        $html = '<div class="scroll"><table><thead><tr>' . implode('', array_map(fn ($h) => '<th>' . $this->h($h) . '</th>', $head)) . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . ($raw ? (string) $c : $this->h((string) $c)) . '</td>', $row)) . '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function filter(string $name, array $options, string $current): string
    {
        return '<p>' . implode(' · ', array_map(fn ($o) => $o === $current ? '<strong>' . $this->h($o) . '</strong>'
            : '<a href="?' . $name . '=' . rawurlencode($o) . '">' . $this->h($o) . '</a>', $options)) . '</p>';
    }

    private function dl(string $k, string $v): string
    {
        return '<dt>' . $this->h($k) . '</dt><dd>' . $this->h($v) . '</dd>';
    }

    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
