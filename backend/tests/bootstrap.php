<?php
declare(strict_types=1);

putenv('BLISSFUL_ENV_FILE=' . __DIR__ . '/.env.test');
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/TestCase.php';

// Fresh schema for every test run.
$db = \Blissful\Db::get();
$db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($db->all('SHOW TABLES') as $row) {
    $db->pdo()->exec('DROP TABLE `' . array_values($row)[0] . '`');
}
$db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
ob_start();
$argvBackup = $GLOBALS['argv'] ?? [];
$GLOBALS['argv'] = ['migrate.php'];
$argv = ['migrate.php'];
require dirname(__DIR__) . '/bin/migrate.php';
ob_end_clean();
$GLOBALS['argv'] = $argvBackup;
