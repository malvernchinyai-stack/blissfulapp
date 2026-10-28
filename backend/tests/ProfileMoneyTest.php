<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Config;
use Blissful\Db;
use Blissful\Service\FileService;
use Blissful\Support\Money;
use Blissful\Support\Totp;

final class ProfileMoneyTest extends TestCase
{
    public function testMoneyIsIntegerOnly(): void
    {
        $this->assertSame(1000, Money::applyBps(10000, 1000));
        $this->assertSame(1, Money::applyBps(5, 1000), '0.5 rounds half up');
        $this->assertSame(0, Money::applyBps(4, 1000));
        $this->assertSame(['price_minor' => 9999, 'extras_minor' => 1, 'subtotal_minor' => 10000, 'fee_bps' => 1000,
            'fee_minor' => 1000, 'total_minor' => 11000, 'musician_receives_minor' => 10000], Money::breakdown(9999, 1, 1000));
        $this->assertSame(12050, Money::parseDecimal('120.5', 'USD'));
        $this->assertSame(12005, Money::parseDecimal('120.05', 'USD'));
        $this->assertNull(Money::parseDecimal('120.005', 'USD'));
        $this->assertNull(Money::parseDecimal('-1', 'USD'));
        $this->assertSame('USD 1,234.05', Money::format(123405, 'USD'));
        // 0.1 + 0.2 style drift cannot happen: summing many cents is exact
        $this->assertSame(30, array_sum(array_fill(0, 3, 10)));
        $this->expectException(\InvalidArgumentException::class);
        Money::applyBps(-1, 100);
    }

    public function testFeeSettingChangesFutureBookingsOnly(): void
    {
        [$c, $m, $b] = $this->confirmedBooking();
        Db::get()->exec("UPDATE settings SET value_json = '1500' WHERE name = 'platform_fee_bps'");
        \Blissful\Support\Settings::flush();
        [$s, $d] = $this->call('GET', "/api/bookings/{$b['id']}", null, $c['token']);
        $this->assertSame(1000, $d['price']['fee_bps']);
        [$s, $cat] = $this->call('GET', '/api/catalog');
        $this->assertSame(1500, $cat['platform_fee_bps']);
    }

    public function testMusicianProfileSearchAndBadges(): void
    {
        $m = $this->musician('keys@example.test', ['keyboard'], ['years_experience' => 12]);
        $this->musician('drums@example.test', ['drums']);
        $c = $this->user('c@example.test');
        [$s, $r] = $this->call('GET', '/api/musicians?instrument_id=' . $this->instr['keyboard'], null, $c['token']);
        $this->assertSame([$m['id']], array_column($r['items'], 'user_id'));
        [$s, $p] = $this->call('GET', "/api/musicians/{$m['id']}", null, $c['token']);
        $this->assertSame(['email'], array_column($p['verifications'], 'kind'), 'only checks that actually succeeded');
        $this->assertArrayNotHasKey('lat', $p, 'exact location is private');
        // a pending ID check shows no badge
        Db::get()->insert('verifications', ['user_id' => $m['id'], 'kind' => 'identity', 'status' => 'pending', 'created_at' => \Blissful\Support\Clock::db()]);
        [$s, $p] = $this->call('GET', "/api/musicians/{$m['id']}", null, $c['token']);
        $this->assertSame(['email'], array_column($p['verifications'], 'kind'));
        Db::get()->exec("UPDATE verifications SET status = 'approved'");
        [$s, $p] = $this->call('GET', "/api/musicians/{$m['id']}", null, $c['token']);
        $this->assertSame(['email', 'identity'], array_column($p['verifications'], 'kind'));
        $this->assertStringContainsString('does not check skills', $p['verifications'][1]['explains']);

        [$s, $x] = $this->call('PUT', '/api/profile/musician', ['stage_name' => 'X', 'city' => 'Harare', 'instrument_ids' => []], $m['token']);
        $this->assertSame(422, $s);
        [$s, $x] = $this->call('PUT', '/api/profile/musician', ['stage_name' => 'Valid', 'city' => 'Harare', 'instrument_ids' => [99999]], $m['token']);
        $this->assertSame(422, $s);
        [$s] = $this->call('POST', '/api/portfolio', ['kind' => 'video_link', 'url' => 'http://insecure.example/video'], $m['token']);
        $this->assertSame(422, $s, 'https links only');
        [$s, $p] = $this->call('POST', '/api/portfolio', ['kind' => 'video_link', 'url' => 'https://www.youtube.com/watch?v=abc', 'title' => 'Live set'], $m['token']);
        $this->assertSame(200, $s);
        $this->assertCount(1, $p['portfolio']);
    }

    public function testUploadsAreValidatedAndPrivateFilesProtected(): void
    {
        $u = $this->user('up@example.test');
        $svc = new FileService();
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, '<?php echo "pwned";');
        try {
            $svc->store($u['id'], 'avatar', ['tmp_name' => $tmp, 'size' => 20, 'error' => UPLOAD_ERR_OK], true);
            $this->fail('PHP disguised as an avatar must be rejected');
        } catch (\Blissful\Http\ApiError $e) {
            $this->assertSame(422, $e->status);
        }
        $png = tempnam(sys_get_temp_dir(), 'up');
        $img = imagecreatetruecolor(4, 4);
        imagepng($img, $png);
        $doc = $svc->store($u['id'], 'verification', ['tmp_name' => $png, 'size' => filesize($png), 'error' => UPLOAD_ERR_OK], true);
        $stored = Db::get()->one('SELECT * FROM files WHERE id = ?', [$doc['id']]);
        $this->assertSame('private', $stored['visibility']);
        $this->assertNotNull($stored['retain_until'], 'verification documents have a retention date');
        $this->assertStringStartsWith(Config::storagePath('uploads/private'), Config::storagePath('uploads/private/' . $stored['storage_name']));
        [$s] = $this->call('GET', "/api/files/{$doc['id']}");
        $this->assertSame(404, $s, 'anonymous');
        $other = $this->user('other@example.test');
        [$s] = $this->call('GET', "/api/files/{$doc['id']}", null, $other['token']);
        $this->assertSame(404, $s, 'other users');
        [$s, , $res] = $this->call('GET', "/api/files/{$doc['id']}", null, $u['token']);
        $this->assertSame(200, $s);
        $this->assertSame('image/png', $res->headers['Content-Type']);
        $big = tempnam(sys_get_temp_dir(), 'up');
        imagepng($img, $big);
        $fh = fopen($big, 'r+');
        ftruncate($fh, 6_000_000);
        fclose($fh);
        $this->expectException(\Blissful\Http\ApiError::class);
        $svc->store($u['id'], 'avatar', ['tmp_name' => $big, 'size' => 6_000_000, 'error' => UPLOAD_ERR_OK], true);
    }

    public function testTotpMatchesRfc6238Vector(): void
    {
        // RFC 6238 Appendix B, SHA-1 secret "12345678901234567890" at T=59 → 94287082 (8 digits) → last 6: 287082
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
    }
}
