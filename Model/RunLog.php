<?php

namespace Kanboard\Plugin\Foreman\Model;

use Closure;
use PDO;
use Pimple\Container;

class RunLog
{
    private PDO $pdo;
    private Closure $clock;

    public function __construct(Container $c, callable $clock)
    {
        $this->pdo = $c['db']->getConnection();
        $this->clock = Closure::fromCallable($clock);
    }

    public function start(string $jobId, string $trigger): int
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $triggerColumn = $driver === 'mysql' ? '`trigger`' : '"trigger"';
        $sql = "INSERT INTO foreman_runs (job_id, $triggerColumn, started_at) VALUES (?, ?, ?)";
        if ($driver === 'pgsql') {
            $sql .= ' RETURNING id';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute([$jobId, $trigger, ($this->clock)()]);

        return (int) ($driver === 'pgsql' ? $statement->fetchColumn() : $this->pdo->lastInsertId());
    }

    public function finish(int $runId, string $status, string $summary, ?string $errorClass): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE foreman_runs SET finished_at = ?, status = ?, summary = ?, error_class = ? WHERE id = ?'
        );
        $statement->execute([($this->clock)(), $status, mb_substr($summary, 0, 200, 'UTF-8'), $errorClass, $runId]);
    }

    public function recent(string $jobId, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM foreman_runs WHERE job_id = ? ORDER BY started_at DESC, id DESC LIMIT ?'
        );
        $statement->bindValue(1, $jobId, PDO::PARAM_STR);
        $statement->bindValue(2, max(0, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
