<?php

namespace Kanboard\Plugin\Foreman\Model;

use Closure;
use PDO;

class Lease
{
    private Closure $clock;
    /** @var array<string, int> */
    private array $claimedUntil = [];

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

        if ($statement->rowCount() !== 1) {
            return false;
        }

        $this->claimedUntil[$jobId] = $until;

        return true;
    }

    public function release(string $jobId): void
    {
        if (!isset($this->claimedUntil[$jobId])) {
            return;
        }

        $this->pdo->prepare('UPDATE foreman_state SET lease_until = NULL WHERE job_id = ? AND lease_until = ?')
            ->execute([$jobId, $this->claimedUntil[$jobId]]);
        unset($this->claimedUntil[$jobId]);
    }
}
