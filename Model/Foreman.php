<?php

namespace Kanboard\Plugin\Foreman\Model;

use Closure;
use PDO;
use Pimple\Container;
use RuntimeException;

class Foreman
{
    private PDO $pdo;
    private Closure $clock;
    private Lease $lease;

    public function __construct(Container $c, ?callable $clock = null)
    {
        $this->pdo = $c['db']->getConnection();
        $this->clock = Closure::fromCallable($clock ?? 'time');
        $this->lease = new Lease($this->pdo, $this->clock);
    }

    public function withLease(string $jobId, callable $fn, int $seconds = 3600): mixed
    {
        $now = ($this->clock)();
        $insert = 'INSERT INTO foreman_state (job_id, plugin, next_due_at) VALUES (?, ?, ?)';
        $onConflict = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE job_id = job_id'
            : 'ON CONFLICT (job_id) DO NOTHING';
        $this->pdo->prepare($insert.' '.$onConflict)->execute([$jobId, 'unknown', $now]);

        if (!$this->lease->claim($jobId, $now + $seconds)) {
            throw new LeaseHeld($jobId);
        }

        try {
            return $fn();
        } finally {
            $this->lease->release($jobId);
        }
    }
}

class LeaseHeld extends RuntimeException
{
}
