<?php
declare(strict_types=1);

// DirectAdmin cron, every minute:  * * * * *  /usr/local/bin/php /home/USER/blissful-gigs/backend/bin/cron.php >/dev/null 2>&1
require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$results = \Blissful\Service\Jobs::runAll();
\Blissful\Support\Log::info('cron_run', ['results' => $results]);
if (in_array('-v', $argv, true)) {
    echo json_encode($results, JSON_PRETTY_PRINT), "\n";
}
