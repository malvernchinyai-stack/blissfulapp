<?php
declare(strict_types=1);

namespace Blissful\Http;

use Blissful\Admin\AdminApp;
use Blissful\Config;
use Blissful\Db;
use Blissful\Payment\SandboxProvider;
use Blissful\Service\PaymentService;
use Blissful\Support\Log;
use Blissful\Support\Money;
use Blissful\Web\Pages;

final class Kernel
{
    public static function handle(Request $q): Response
    {
        $requestId = bin2hex(random_bytes(6));
        try {
            $p = $q->path;
            if ($p === '/health') {
                return Api::health();
            }
            if (preg_match('#^/api/files/(\d+)$#', $p, $m) && $q->method === 'GET') {
                return Api::file($q, (int) $m[1]);
            }
            if (str_starts_with($p, '/api/') || $p === '/api') {
                return Api::handle($q);
            }
            if (preg_match('#^/webhooks/(sandbox|paynow)$#', $p, $m) && $q->method === 'POST') {
                $status = (new PaymentService())->handleCallback($m[1], $q->post, $q->rawBody);
                return Response::json(['received' => $status < 300], $status);
            }
            if (preg_match('#^/sandbox/pay/([A-Za-z0-9\-]{4,64})$#', $p, $m)) {
                return self::sandboxCheckout($q, $m[1]);
            }
            if ($p === '/payment-return') {
                return Response::html(Pages::paymentReturn());
            }
            if ($p === '/admin' || str_starts_with($p, '/admin/')) {
                return (new AdminApp())->handle($q);
            }
            return match ($p) {
                '/' => Response::html(Pages::landing()),
                '/privacy' => Response::html(Pages::privacy()),
                '/terms' => Response::html(Pages::terms()),
                '/robots.txt' => new Response(200, "User-agent: *\nDisallow: /\n", ['Content-Type' => 'text/plain']),
                '/download/app.apk' => self::apk($q),
                default => Response::html(Pages::notFound(), 404),
            };
        } catch (ApiError $e) {
            return Response::error($e);
        } catch (\Throwable $e) {
            Log::error('unhandled', ['request_id' => $requestId, 'path' => $q->path, 'error' => get_class($e) . ': ' . $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine()]);
            $body = ['error' => ['code' => 'server_error', 'message' => 'Something went wrong on our side. Nothing was changed if you see this message; please try again.', 'request_id' => $requestId]];
            if (Config::env() === 'development') {
                $body['error']['debug'] = $e->getMessage();
            }
            return Response::json($body, 500);
        }
    }

    /** Clearly-labelled fake checkout. Only exists when the sandbox provider is active outside production. */
    private static function sandboxCheckout(Request $q, string $ref): Response
    {
        if (Config::env() === 'production' || Config::get('PAYMENT_PROVIDER', 'sandbox') !== 'sandbox') {
            return Response::html(Pages::notFound(), 404);
        }
        $sig = is_string($q->query['sig'] ?? null) ? $q->query['sig'] : '';
        if (!hash_equals(SandboxProvider::sign(['reference' => $ref]), $sig)) {
            return Response::html(Pages::notFound(), 404);
        }
        $p = Db::get()->one('SELECT * FROM payments WHERE reference = ? AND provider = ?', [$ref, 'sandbox']);
        if ($p === null) {
            return Response::html(Pages::notFound(), 404);
        }
        if ($q->method === 'POST') {
            $outcome = in_array($q->post['outcome'] ?? '', ['paid', 'failed', 'cancelled'], true) ? $q->post['outcome'] : 'failed';
            $fields = ['reference' => $ref, 'status' => $outcome, 'amount_minor' => (string) $p['amount_minor'], 'provider_ref' => (string) $p['provider_ref'],
                'ts' => (string) time()];
            $fields['signature'] = SandboxProvider::sign($fields);
            $raw = http_build_query($fields);
            (new PaymentService())->handleCallback('sandbox', $fields, $raw);
            return Response::html(Pages::sandboxDone($outcome));
        }
        return Response::html(Pages::sandboxCheckout($ref, Money::format((int) $p['amount_minor'], $p['currency']), $p['status'], $sig));
    }

    private static function apk(Request $q): Response
    {
        $path = Config::get('TEST_APK_PATH');
        $user = Config::get('DOWNLOAD_USER');
        $pass = Config::get('DOWNLOAD_PASS');
        if ($user !== null && $pass !== null) {
            $h = $q->header('authorization') ?? '';
            $ok = str_starts_with($h, 'Basic ') && hash_equals(base64_encode($user . ':' . $pass), substr($h, 6));
            if (!$ok) {
                return new Response(401, 'Authentication required', ['WWW-Authenticate' => 'Basic realm="Blissful Gigs testers"']);
            }
        }
        if ($path === null || !is_file($path)) {
            return Response::html(Pages::notFound('The test build has not been uploaded yet.'), 404);
        }
        return new Response(200, (string) file_get_contents($path), [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Content-Disposition' => 'attachment; filename="blissful-gigs-testing.apk"',
        ]);
    }
}
