<?php
declare(strict_types=1);

// Separate OS process with its own DB connection. Waits for a shared start time, then accepts one offer.
// Usage: php concurrency_worker.php <clientId> <offerId> <version> <startAtMicrotime>
putenv('BLISSFUL_ENV_FILE=' . __DIR__ . '/.env.test');
require dirname(__DIR__) . '/src/bootstrap.php';

[$_, $clientId, $offerId, $version, $startAt] = $argv;
\Blissful\Db::get()->value('SELECT 1'); // connect before the barrier
while (microtime(true) < (float) $startAt) {
    usleep(200);
}
try {
    (new \Blissful\Service\OfferService())->accept((int) $clientId, (int) $offerId, ['expected_version' => (int) $version]);
    echo json_encode(['ok' => true]);
} catch (\Blissful\Http\ApiError $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'exception', 'msg' => $e->getMessage()]);
}
