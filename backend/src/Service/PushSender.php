<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Config;
use Blissful\Support\Log;

/**
 * Firebase Cloud Messaging HTTP v1 sender using a service-account key (stored outside the web root).
 * STATUS: not exercised against Firebase in this build — requires a Firebase project. When
 * FCM_SERVICE_ACCOUNT_FILE is unset, push is reported as not configured and only in-app history is used.
 */
class PushSender
{
    public function configured(): bool
    {
        $f = Config::get('FCM_SERVICE_ACCOUNT_FILE');
        return $f !== null && $f !== '' && is_readable($f);
    }

    /** @return string 'sent' | 'invalid_token' | 'retry' */
    public function send(string $deviceToken, string $title, string $body, array $data): string
    {
        $sa = json_decode((string) file_get_contents((string) Config::get('FCM_SERVICE_ACCOUNT_FILE')), true);
        $access = $this->accessToken($sa);
        if ($access === null) {
            return 'retry';
        }
        $payload = ['message' => [
            'token' => $deviceToken,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'bookings']],
        ]];
        $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200) {
            return 'sent';
        }
        if ($code === 404 || ($code === 400 && is_string($resp) && str_contains($resp, 'INVALID_ARGUMENT') && str_contains($resp, 'token'))) {
            return 'invalid_token';
        }
        Log::warning('fcm_send_failed', ['http' => $code]);
        return 'retry';
    }

    private function accessToken(array $sa): ?string
    {
        $cache = Config::storagePath('cache/fcm_token.json');
        if (is_file($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c) && ($c['exp'] ?? 0) > time() + 60) {
                return $c['token'];
            }
        }
        $now = time();
        $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $jwt = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
            'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        ]));
        if (!openssl_sign($jwt, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $jwt .= '.' . $b64($sig);
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt])]);
        $resp = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (!isset($resp['access_token'])) {
            return null;
        }
        @mkdir(dirname($cache), 0750, true);
        file_put_contents($cache, json_encode(['token' => $resp['access_token'], 'exp' => $now + (int) ($resp['expires_in'] ?? 3600)]));
        return $resp['access_token'];
    }
}
