<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Tynd PDO-wrapper omkring MySQL-forbindelsen.
 */
class Database
{
    private PDO $pdo;

    public function __construct(array $cfg)
    {
        // 'dsn' kan angives direkte (fx til at skifte host/dbnavn pr. miljø i config.php);
        // ellers bygges den af de enkelte dele som før.
        $dsn = $cfg['dsn'] ?? sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], $cfg['port'] ?? 3306, $cfg['name'], $cfg['charset'] ?? 'utf8mb4'
        );
        $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Kør en query med parametre og returnér PDOStatement. */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Hent alle rækker. */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** Hent én række (eller null). */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Hent en enkelt skalarværdi. */
    public function scalar(string $sql, array $params = [])
    {
        return $this->run($sql, $params)->fetchColumn();
    }

    public function begin(): void   { $this->pdo->beginTransaction(); }
    public function commit(): void  { $this->pdo->commit(); }
    public function rollBack(): void{ if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }
    public function lastId(): string{ return $this->pdo->lastInsertId(); }
}
