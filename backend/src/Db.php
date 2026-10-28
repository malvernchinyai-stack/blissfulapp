<?php
declare(strict_types=1);

namespace Blissful;

use PDO;
use PDOException;

final class Db
{
    private static ?Db $instance = null;
    private PDO $pdo;
    private int $depth = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function get(): Db
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', '127.0.0.1'),
                Config::get('DB_PORT', '3306'),
                Config::require('DB_NAME')
            );
            $pdo = new PDO($dsn, Config::require('DB_USER'), Config::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            self::$instance = new Db($pdo);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_map(fn ($c) => ':' . $c, $cols))
        );
        $st = $this->pdo->prepare($sql);
        $st->execute($row);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Runs $fn inside a transaction. Nested calls join the outer transaction.
     * Deadlocks / lock wait timeouts at the outermost level are retried up to 3 times.
     */
    public function tx(callable $fn): mixed
    {
        if ($this->depth > 0) {
            return $fn($this);
        }
        $attempt = 0;
        while (true) {
            $attempt++;
            $this->pdo->beginTransaction();
            $this->depth = 1;
            try {
                $result = $fn($this);
                $this->pdo->commit();
                $this->depth = 0;
                return $result;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->depth = 0;
                $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if (in_array($code, [1213, 1205], true) && $attempt < 3) {
                    usleep(random_int(20_000, 80_000));
                    continue;
                }
                throw $e;
            }
        }
    }
}
