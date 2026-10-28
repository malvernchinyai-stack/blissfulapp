<?php
declare(strict_types=1);

// Applies migrations/NNN_*.sql in order, once each. Usage: php bin/migrate.php [--status]
require dirname(__DIR__) . '/src/bootstrap.php';

use Blissful\Db;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$db = Db::get();
$db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB');
$applied = array_column($db->all('SELECT version FROM schema_migrations'), 'version');
$files = glob(dirname(__DIR__) . '/migrations/*.sql');
sort($files, SORT_STRING);
$status = in_array('--status', $argv, true);
$ran = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $applied, true)) {
        $status && print("  applied  {$version}\n");
        continue;
    }
    if ($status) {
        echo "  pending  {$version}\n";
        continue;
    }
    echo "Applying {$version} ... ";
    $sql = file_get_contents($file);
    // MySQL DDL auto-commits, so each statement runs individually; a failure stops the run with a clear message.
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql))));
    foreach ($statements as $stmt) {
        $db->pdo()->exec($stmt);
    }
    $db->exec('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())', [$version]);
    echo "done\n";
    $ran++;
}
if (!$status) {
    echo $ran === 0 ? "Database is up to date.\n" : "Applied {$ran} migration(s).\n";
}
