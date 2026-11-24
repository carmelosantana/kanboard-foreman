<?php

namespace Kanboard\Plugin\Foreman\Model;

use Closure;
use PDO;

class Lease
{
    private Closure $clock;

    public function __construct(private PDO $pdo, callable $clock)
    {
        $this->clock = Closure::fromCallable($clock);
    }

    public function claim(string $jobId, int $until): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE foreman_state SET lease_until = ?
             WHERE job_id = ? AND (lease_until IS NULL OR lease_until < ?)'
        );
        $statement->execute([$until, $jobId, ($this->clock)()]);

        return $statement->rowCount() === 1;
    }

    public function release(string $jobId): void
    {
        $this->pdo->prepare('UPDATE foreman_state SET lease_until = NULL WHERE job_id = ?')
            ->execute([$jobId]);
    }
}
