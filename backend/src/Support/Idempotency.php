<?php
declare(strict_types=1);

namespace Blissful\Support;

use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Http\Request;
use Blissful\Http\Response;

/**
 * Lets the app safely retry a POST after a dropped connection. The client sends an
 * `Idempotency-Key` header; the first completed response is stored and replayed for retries.
 */
final class Idempotency
{
    public static function run(Request $req, callable $handler): Response
    {
        $key = $req->header('idempotency-key');
        if ($key === null || $req->user === null) {
            return $handler();
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $key)) {
            throw new ApiError(400, 'bad_idempotency_key', 'Invalid Idempotency-Key header.');
        }
        $uid = (int) $req->user['id'];
        $hash = hash('sha256', $req->method . ' ' . $req->path . ' ' . $req->rawBody);
        $db = Db::get();
        $existing = $db->one('SELECT * FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [$uid, $key]);
        if ($existing !== null) {
            if (!hash_equals($existing['request_hash'], $hash)) {
                throw new ApiError(422, 'idempotency_mismatch', 'This Idempotency-Key was used for a different request.');
            }
            if ($existing['status_code'] === null) {
                throw new ApiError(409, 'request_in_progress', 'The original request is still being processed. Retry shortly.');
            }
            $r = Response::json(json_decode($existing['response_body'], true), (int) $existing['status_code']);
            $r->headers['Idempotent-Replay'] = 'true';
            return $r;
        }
        try {
            $db->insert('idempotency_keys', ['user_id' => $uid, 'idem_key' => $key, 'request_hash' => $hash, 'created_at' => Clock::db()]);
        } catch (\PDOException) {
            throw new ApiError(409, 'request_in_progress', 'The original request is still being processed. Retry shortly.');
        }
        try {
            $response = $handler();
        } catch (ApiError $e) {
            $response = Response::error($e);
        } catch (\Throwable $e) {
            $db->exec('DELETE FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [$uid, $key]);
            throw $e;
        }
        if ($response->status >= 500) {
            $db->exec('DELETE FROM idempotency_keys WHERE user_id = ? AND idem_key = ?', [$uid, $key]);
        } else {
            $db->exec('UPDATE idempotency_keys SET status_code = ?, response_body = ? WHERE user_id = ? AND idem_key = ?',
                [$response->status, $response->body, $uid, $key]);
        }
        return $response;
    }

    public static function purge(): int
    {
        return Db::get()->exec('DELETE FROM idempotency_keys WHERE created_at < ?', [Clock::db(Clock::now()->modify('-2 days'))]);
    }
}
