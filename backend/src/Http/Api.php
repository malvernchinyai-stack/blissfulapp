<?php
declare(strict_types=1);

namespace Blissful\Http;

use Blissful\Config;
use Blissful\Db;
use Blissful\Service\AuthService;
use Blissful\Service\BookingService;
use Blissful\Service\ChatService;
use Blissful\Service\FileService;
use Blissful\Service\NotificationService;
use Blissful\Service\OfferService;
use Blissful\Service\PaymentService;
use Blissful\Service\ProfileService;
use Blissful\Service\RequestService;
use Blissful\Service\ReviewService;
use Blissful\Service\SafetyService;
use Blissful\Support\Mailer;
use Blissful\Support\RateLimiter;
use Blissful\Support\Validator;

/** All /api routes. Every handler receives the authenticated Request; services re-check ownership. */
final class Api
{
    public static function router(): Router
    {
        $r = new Router();
        $auth = new AuthService();
        $open = ['auth' => false];
        $musician = ['role' => 'musician'];

        // ---- Auth & account
        $r->add('POST', '/api/auth/register', fn (Request $q) => [$auth->register($q->json(), $q->ip), 202], $open);
        $r->add('POST', '/api/auth/verify-email', fn (Request $q) => $auth->verifyEmail($q->json(), $q->ip), $open);
        $r->add('POST', '/api/auth/resend-code', fn (Request $q) => [$auth->resendVerification($q->json(), $q->ip), 202], $open);
        $r->add('POST', '/api/auth/login', fn (Request $q) => $auth->login($q->json(), $q->ip), $open);
        $r->add('POST', '/api/auth/refresh', fn (Request $q) => $auth->refresh($q->json(), $q->ip), $open);
        $r->add('POST', '/api/auth/forgot-password', fn (Request $q) => [$auth->forgotPassword($q->json(), $q->ip), 202], $open);
        $r->add('POST', '/api/auth/reset-password', fn (Request $q) => $auth->resetPassword($q->json(), $q->ip), $open);
        $r->add('POST', '/api/auth/logout', function (Request $q) use ($auth) {
            $auth->logout($q->user);
            return ['status' => 'signed_out'];
        });
        $r->add('GET', '/api/me', fn (Request $q) => $auth->me($q->userId()));
        $r->add('POST', '/api/me/role', fn (Request $q) => $auth->switchRole($q->user, $q->json()));
        $r->add('POST', '/api/me/password', fn (Request $q) => $auth->changePassword($q->user, $q->json()));
        $r->add('POST', '/api/me/delete', fn (Request $q) => $auth->deleteAccount($q->user, $q->json()));

        // ---- Catalogue & profiles
        $profiles = new ProfileService();
        $r->add('GET', '/api/catalog', fn () => $profiles->catalog(), $open);
        $r->add('GET', '/api/profile', fn (Request $q) => $profiles->myProfiles($q->userId()));
        $r->add('PUT', '/api/profile/client', fn (Request $q) => $profiles->updateClient($q->userId(), $q->json()));
        $r->add('PUT', '/api/profile/musician', fn (Request $q) => $profiles->upsertMusician($q->userId(), $q->json()));
        $r->add('GET', '/api/musicians', fn (Request $q) => $profiles->search($q->query, $q->userId()));
        $r->add('GET', '/api/musicians/{id}', fn (Request $q) => $profiles->musicianProfile($q->intParam('id'), $q->userId()));
        $r->add('GET', '/api/musicians/{id}/reviews', fn (Request $q) => ['items' => $profiles->reviewsFor($q->intParam('id'), 'musician', 20, $q->queryInt('page', 1, 1, 500))]);
        $r->add('GET', '/api/clients/{id}', fn (Request $q) => $profiles->clientProfile($q->intParam('id')));
        $r->add('POST', '/api/portfolio', fn (Request $q) => $profiles->addPortfolio($q->userId(), $q->json()), $musician);
        $r->add('DELETE', '/api/portfolio/{id}', fn (Request $q) => $profiles->deletePortfolio($q->userId(), $q->intParam('id')), $musician);
        $r->add('GET', '/api/availability', fn (Request $q) => ['items' => $profiles->availability($q->userId())], $musician);
        $r->add('POST', '/api/availability', fn (Request $q) => ['items' => $profiles->addUnavailable($q->userId(), $q->json())], $musician);
        $r->add('DELETE', '/api/availability/{id}', fn (Request $q) => ['items' => $profiles->deleteUnavailable($q->userId(), $q->intParam('id'))], $musician);
        $r->add('POST', '/api/verifications', fn (Request $q) => $profiles->requestVerification($q->userId(), $q->json()));

        // ---- Files
        $files = new FileService();
        $r->add('POST', '/api/files', function (Request $q) use ($files) {
            RateLimiter::enforce('upload:' . $q->userId(), 30, 3600);
            $purpose = is_string($q->post['purpose'] ?? null) ? $q->post['purpose'] : '';
            return [$files->store($q->userId(), $purpose, $q->files['file'] ?? []), 201];
        });
        $r->add('DELETE', '/api/files/{id}', function (Request $q) use ($files) {
            $files->softDelete($q->userId(), $q->intParam('id'));
            return ['status' => 'deleted'];
        });

        // ---- Requests
        $requests = new RequestService();
        $r->add('POST', '/api/requests', fn (Request $q) => [$requests->create($q->userId(), $q->json()), 201], ['rate' => ['req_create', 30, 86400]]);
        $r->add('GET', '/api/requests/mine', fn (Request $q) => $requests->mine($q->userId(), $q->query));
        $r->add('GET', '/api/requests/feed', fn (Request $q) => $requests->feed($q->userId(), $q->query), $musician);
        $r->add('GET', '/api/requests/{id}', fn (Request $q) => $requests->detail($q->intParam('id'), $q->userId()));
        $r->add('PATCH', '/api/requests/{id}', fn (Request $q) => $requests->update($q->userId(), $q->intParam('id'), $q->json()));
        $r->add('POST', '/api/requests/{id}/publish', fn (Request $q) => $requests->publish($q->userId(), $q->intParam('id')));
        $r->add('POST', '/api/requests/{id}/cancel', fn (Request $q) => $requests->cancel($q->userId(), $q->intParam('id')));

        // ---- Offers
        $offers = new OfferService();
        $r->add('GET', '/api/requests/{id}/offers', fn (Request $q) => $offers->listForRequest($q->userId(), $q->intParam('id'), $q->query));
        $r->add('POST', '/api/requests/{id}/offers', fn (Request $q) => [$offers->submit($q->userId(), $q->intParam('id'), $q->json()), 201], $musician + ['rate' => ['offer_create', 60, 3600]]);
        $r->add('GET', '/api/offers/mine', fn (Request $q) => $offers->mine($q->userId(), $q->query), $musician);
        $r->add('GET', '/api/offers/{id}', fn (Request $q) => $offers->view($q->intParam('id'), $q->userId()));
        $r->add('PATCH', '/api/offers/{id}', fn (Request $q) => $offers->edit($q->userId(), $q->intParam('id'), $q->json()), $musician);
        $r->add('POST', '/api/offers/{id}/withdraw', fn (Request $q) => $offers->withdraw($q->userId(), $q->intParam('id')), $musician);
        $r->add('POST', '/api/offers/{id}/counter', fn (Request $q) => $offers->counter($q->userId(), $q->intParam('id'), $q->json()));
        $r->add('POST', '/api/offers/{id}/counter-response', fn (Request $q) => $offers->respondCounter($q->userId(), $q->intParam('id'), $q->json()), $musician);
        $r->add('POST', '/api/offers/{id}/accept', fn (Request $q) => $offers->accept($q->userId(), $q->intParam('id'), $q->json()));
        $r->add('POST', '/api/offers/{id}/reject', fn (Request $q) => $offers->reject($q->userId(), $q->intParam('id')));

        // ---- Bookings
        $bookings = new BookingService();
        $r->add('GET', '/api/bookings', fn (Request $q) => $bookings->list($q->userId(), $q->query));
        $r->add('GET', '/api/bookings/{id}', fn (Request $q) => $bookings->detail($q->intParam('id'), $q->userId()));
        $r->add('GET', '/api/bookings/{id}/events', fn (Request $q) => ['items' => $bookings->events($q->intParam('id'), $q->userId())]);
        $r->add('POST', '/api/bookings/{id}/done', fn (Request $q) => $bookings->markDone($q->userId(), $q->intParam('id')));
        $r->add('POST', '/api/bookings/{id}/cancel', fn (Request $q) => $bookings->cancel($q->userId(), $q->intParam('id'), $q->json()));
        $r->add('POST', '/api/bookings/{id}/reschedule', fn (Request $q) => $bookings->proposeReschedule($q->userId(), $q->intParam('id'), $q->json()));
        $r->add('POST', '/api/bookings/{id}/reschedule/{pid}', fn (Request $q) => $bookings->respondReschedule($q->userId(), $q->intParam('id'), $q->intParam('pid'), $q->json()));
        $r->add('POST', '/api/bookings/{id}/dispute', fn (Request $q) => (new SafetyService())->openDispute($q->userId(), $q->intParam('id'), $q->json()));
        $r->add('POST', '/api/bookings/{id}/review', fn (Request $q) => [(new ReviewService())->create($q->userId(), $q->intParam('id'), $q->json()), 201]);

        // ---- Chat
        $chat = new ChatService();
        $r->add('GET', '/api/bookings/{id}/messages', fn (Request $q) => $chat->list($q->userId(), $q->intParam('id'), $q->query));
        $r->add('POST', '/api/bookings/{id}/messages', fn (Request $q) => [$chat->send($q->userId(), $q->intParam('id'), $q->json()), 201]);
        $r->add('GET', '/api/messages/unread', fn (Request $q) => ['items' => $chat->unreadCounts($q->userId())]);

        // ---- Payments
        $payments = new PaymentService();
        $r->add('POST', '/api/bookings/{id}/payments', fn (Request $q) => [$payments->start($q->userId(), $q->intParam('id')), 201], ['rate' => ['pay_start', 20, 3600]]);
        $r->add('GET', '/api/bookings/{id}/payments', fn (Request $q) => ['items' => $payments->forBooking($q->userId(), $q->intParam('id'))]);

        // ---- Notifications
        $notes = new NotificationService();
        $r->add('GET', '/api/notifications', fn (Request $q) => $notes->list($q->userId(),
            isset($q->query['before']) && ctype_digit((string) $q->query['before']) ? (int) $q->query['before'] : null, 30));
        $r->add('POST', '/api/notifications/read', fn (Request $q) => $notes->markRead($q->userId(), $q->json()));
        $r->add('GET', '/api/notifications/prefs', fn (Request $q) => ['items' => $notes->prefs($q->userId())]);
        $r->add('PUT', '/api/notifications/prefs', fn (Request $q) => ['items' => $notes->updatePrefs($q->userId(), $q->json())]);
        $r->add('POST', '/api/devices', fn (Request $q) => $notes->registerDevice($q->userId(), $q->json()));
        $r->add('POST', '/api/devices/remove', fn (Request $q) => $notes->unregisterDevice($q->userId(), $q->json()));

        // ---- Safety & support
        $safety = new SafetyService();
        $r->add('GET', '/api/blocks', fn (Request $q) => ['items' => $safety->blocks($q->userId())]);
        $r->add('POST', '/api/blocks/{id}', fn (Request $q) => ['items' => $safety->block($q->userId(), $q->intParam('id'))]);
        $r->add('DELETE', '/api/blocks/{id}', fn (Request $q) => ['items' => $safety->unblock($q->userId(), $q->intParam('id'))]);
        $r->add('POST', '/api/reports', fn (Request $q) => [$safety->report($q->userId(), $q->json()), 201]);
        $r->add('POST', '/api/support', function (Request $q) {
            $v = new Validator($q->json());
            $subject = $v->string('subject', 3, 150);
            $message = $v->string('message', 10, 4000);
            $v->done();
            RateLimiter::enforce('support:' . $q->userId(), 5, 86400);
            $sent = Mailer::send((string) Config::get('SUPPORT_EMAIL', 'support@blissfulevents.co.zw'), 'Support: ' . $subject,
                "From user #{$q->userId()} ({$q->user['email']})\n\n{$message}");
            if (!$sent) {
                throw new ApiError(503, 'support_unavailable', 'We could not send your message. Please try again later.');
            }
            return [['status' => 'sent'], 202];
        });

        return $r;
    }

    public static function handle(Request $q): Response
    {
        static $router = null;
        $router ??= self::router();
        $exists = false;
        $match = $router->match($q->method, $q->path, $exists);
        if ($match === null) {
            return Response::error($exists ? new ApiError(405, 'method_not_allowed', 'Method not allowed.') : ApiError::notFound('Endpoint'));
        }
        [$handler, $params, $opts] = $match;
        $q->params = $params;

        $token = $q->bearer();
        if ($token !== null) {
            $q->user = (new AuthService())->authenticate($token);
        }
        if ($opts['auth'] && $q->user === null) {
            return Response::error(ApiError::unauthorized($token === null ? 'Please sign in.' : 'Your session has expired. Please sign in again.'));
        }
        if (($opts['role'] ?? null) === 'musician' && !(int) $q->user['is_musician']) {
            return Response::error(ApiError::forbidden('Switch on your musician role to use this feature.'));
        }
        $bucket = $q->user !== null ? 'api:user:' . $q->user['id'] : 'api:ip:' . $q->ip;
        if (!RateLimiter::hit($bucket, $q->user !== null ? 180 : 60, 60)) {
            return Response::error(new ApiError(429, 'rate_limited', 'Too many requests. Please slow down.'));
        }
        if (isset($opts['rate'])) {
            [$name, $limit, $window] = $opts['rate'];
            if (!RateLimiter::hit($name . ':' . ($q->user['id'] ?? $q->ip), $limit, $window)) {
                return Response::error(new ApiError(429, 'rate_limited', 'You have reached the limit for this action. Try again later.'));
            }
        }
        $run = function () use ($handler, $q): Response {
            $result = $handler($q);
            if ($result instanceof Response) {
                return $result;
            }
            if (is_array($result) && array_is_list($result) && count($result) === 2 && is_int($result[1])) {
                return Response::json($result[0], $result[1]);
            }
            return Response::json($result);
        };
        if (in_array($q->method, ['POST', 'PATCH', 'PUT', 'DELETE'], true) && $q->user !== null) {
            return \Blissful\Support\Idempotency::run($q, $run);
        }
        return $run();
    }

    /** Streams an uploaded file. Public files need no auth; private ones need the owner's token. */
    public static function file(Request $q, int $id): Response
    {
        $token = $q->bearer();
        $user = $token !== null ? (new AuthService())->authenticate($token) : null;
        $f = (new FileService())->resolveForDownload($id, $user);
        if ($f === null) {
            return Response::error(ApiError::notFound('File'));
        }
        $resp = new Response(200, (string) file_get_contents($f['path']), [
            'Content-Type' => $f['mime'],
            'Content-Length' => (string) $f['size'],
            'Cache-Control' => $user !== null ? 'private, max-age=300' : 'public, max-age=86400',
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'",
        ]);
        return $resp;
    }

    public static function health(): Response
    {
        try {
            Db::get()->value('SELECT 1');
            return Response::json(['status' => 'ok']);
        } catch (\Throwable) {
            return Response::json(['status' => 'unavailable'], 503);
        }
    }
}
