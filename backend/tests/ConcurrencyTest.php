<?php
declare(strict_types=1);

namespace Blissful\Tests;

use Blissful\Db;
use Blissful\Support\Clock;

/** Races real OS processes against MariaDB to prove acceptance is atomic. */
final class ConcurrencyTest extends TestCase
{
    /** @return array<int,array> decoded worker outputs */
    private function race(array $jobs): array
    {
        $start = microtime(true) + 1.5;
        $procs = [];
        foreach ($jobs as [$clientId, $offerId, $version]) {
            $cmd = sprintf('php %s %d %d %d %F', escapeshellarg(__DIR__ . '/concurrency_worker.php'), $clientId, $offerId, $version, $start);
            $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $outs[] = $pipes;
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $out = stream_get_contents($outs[$i][1]);
            $err = stream_get_contents($outs[$i][2]);
            proc_close($p);
            $decoded = json_decode((string) $out, true);
            $this->assertIsArray($decoded, "worker output: {$out} {$err}");
            $this->assertNotSame('exception', $decoded['code'] ?? null, $decoded['msg'] ?? '');
            $results[] = $decoded;
        }
        return $results;
    }

    public function testManyOffersOneSeat(): void
    {
        $c = $this->user('client@example.test');
        $req = $this->openRequest($c['token']);
        $jobs = [];
        for ($i = 0; $i < 8; $i++) {
            $m = $this->musician("m{$i}@example.test");
            $o = $this->offer($m['token'], $req);
            $jobs[] = [$c['id'], $o['id'], $o['version']];
        }
        $results = $this->race($jobs);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $this->assertSame(1, (int) Db::get()->value('SELECT COUNT(*) FROM bookings'));
        $this->assertSame(1, (int) Db::get()->value('SELECT filled_count FROM request_positions'));
        $this->assertSame(1, (int) Db::get()->value("SELECT COUNT(*) FROM offers WHERE status = 'accepted'"));
    }

    public function testSameOfferAcceptedTwiceAtOnce(): void
    {
        $c = $this->user('client@example.test');
        $m = $this->musician('m@example.test');
        $req = $this->openRequest($c['token']);
        $o = $this->offer($m['token'], $req);
        $results = $this->race(array_fill(0, 6, [$c['id'], $o['id'], $o['version']]));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $this->assertSame(1, (int) Db::get()->value('SELECT COUNT(*) FROM bookings'));
    }

    public function testCapacityTwoUnderLoad(): void
    {
        $c = $this->user('client@example.test');
        $req = $this->openRequest($c['token'], ['positions' => [['instrument_id' => $this->instr['vocals'], 'quantity' => 2, 'budget_minor' => 5000]]]);
        $jobs = [];
        for ($i = 0; $i < 7; $i++) {
            $m = $this->musician("v{$i}@example.test", ['vocals']);
            $o = $this->offer($m['token'], $req);
            $jobs[] = [$c['id'], $o['id'], $o['version']];
        }
        $results = $this->race($jobs);
        $this->assertSame(2, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $this->assertSame(2, (int) Db::get()->value('SELECT COUNT(*) FROM bookings'));
        $this->assertSame('filled', Db::get()->value('SELECT status FROM request_positions'));
    }

    public function testOneMusicianTwoClientsSameTime(): void
    {
        $m = $this->musician('popular@example.test');
        $jobs = [];
        for ($i = 0; $i < 4; $i++) {
            $c = $this->user("c{$i}@example.test");
            $req = $this->openRequest($c['token'], ['starts_at' => Clock::now()->modify('+10 days')->setTime(12, 15 * $i)->format('Y-m-d\TH:i:s\Z')]);
            $o = $this->offer($m['token'], $req);
            $jobs[] = [$c['id'], $o['id'], $o['version']];
        }
        $results = $this->race($jobs);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $codes = array_column(array_filter($results, fn ($r) => !$r['ok']), 'code');
        $this->assertSame(['schedule_conflict'], array_values(array_unique($codes)));
    }
}
