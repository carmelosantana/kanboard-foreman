<?php

namespace Kanboard\Plugin\Foreman\Model;

use Closure;
use DateTimeZone;
use PDO;
use Pimple\Container;
use Symfony\Component\EventDispatcher\GenericEvent;
use Throwable;
use UnexpectedValueException;

class Runner
{
    private PDO $pdo;
    private Closure $clock;
    private Lease $lease;
    private RunLog $log;

    public function __construct(private Container $c, callable $clock)
    {
        $this->pdo = $c['db']->getConnection();
        $this->clock = Closure::fromCallable($clock);
        $this->lease = new Lease($this->pdo, $clock);
        $this->log = new RunLog($c, $clock);
    }

    public function hasDueJob(): bool
    {
        $ids = array_column($this->c['foremanRegistry']->jobs(), 'id');
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM foreman_state WHERE '.$this->enabledJobs($ids).' AND next_due_at <= ? LIMIT 1'
        );
        $statement->execute([...$ids, ($this->clock)()]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array{job_id: string, status: 'done'|'more'|'failed', summary: string}> */
    public function tick(string $trigger, int $deadline, ?string $onlyJob = null): array
    {
        $registry = $this->c['foremanRegistry'];
        $registry->sync();
        $jobs = $registry->jobs();
        if ($onlyJob !== null && !isset($jobs[$onlyJob])) {
            return [];
        }

        $ids = $onlyJob === null ? array_column($jobs, 'id') : [$onlyJob];
        $sql = 'SELECT job_id FROM foreman_state WHERE '.$this->enabledJobs($ids);
        if ($onlyJob === null) {
            $sql .= ' AND next_due_at <= ?';
            $ids[] = ($this->clock)();
        }
        $statement = $this->pdo->prepare($sql.' ORDER BY next_due_at, job_id');
        $statement->execute($ids);
        $candidates = $statement->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        $tz = Schedule::tz($this->c);
        foreach ($candidates as $id) {
            if (($this->clock)() >= $deadline) {
                break;
            }
            $result = $this->runJob($jobs[$id], $trigger, $deadline, $tz, $onlyJob !== null);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function enabledJobs(array $ids): string
    {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return 'enabled = 1 AND job_id IN ('.$placeholders.')';
    }

    private function runJob(array $job, string $trigger, int $deadline, DateTimeZone $tz, bool $force): ?array
    {
        $id = $job['id'];
        $leaseUntil = $deadline + 60;
        if (!$this->lease->claim($id, $leaseUntil)) {
            return null;
        }

        try {
            // Another tick may have run this job since we selected the candidates.
            $statement = $this->pdo->prepare(
                'SELECT enabled, next_due_at, consecutive_failures FROM foreman_state WHERE job_id = ?'
            );
            $statement->execute([$id]);
            $state = $statement->fetch(PDO::FETCH_ASSOC);
            $started = ($this->clock)();
            if ($state === false || !(int) $state['enabled'] || $started >= $deadline
                || (!$force && (int) $state['next_due_at'] > $started)) {
                return null;
            }

            $runId = $this->log->start($id, $trigger);
            $this->pdo->prepare('UPDATE foreman_state SET last_started_at = ? WHERE job_id = ?')
                ->execute([$started, $id]);

            $jobDeadline = $trigger === 'web' ? min($deadline, $started + $job['budget']) : $deadline;
            [$status, $summary, $errorClass] = $this->executeJob($job['run'], $jobDeadline, $trigger);
            $finished = ($this->clock)();
            $this->log->finish($runId, $status, $summary, $errorClass);

            $previousFailures = (int) $state['consecutive_failures'];
            $failures = match ($status) {
                'failed' => $previousFailures + 1,
                'done' => 0,
                'more' => $previousFailures,
            };
            $statement = $this->pdo->prepare(
                'UPDATE foreman_state SET last_finished_at = ?, last_status = ?, consecutive_failures = ?, next_due_at = ?
                 WHERE job_id = ? AND lease_until = ?'
            );
            $statement->execute([
                $finished, $status, $failures, Schedule::nextDue($job, $status, $finished, $tz), $id, $leaseUntil,
            ]);
            $recorded = $statement->rowCount() === 1;
        } finally {
            $this->lease->release($id);
        }

        if ($recorded && ($status === 'failed' || ($status === 'done' && $previousFailures > 0))) {
            $this->signal($job, $runId, $status, $failures, $errorClass);
        }

        return ['job_id' => $id, 'status' => $status, 'summary' => $summary];
    }

    private function executeJob(callable $run, int $deadline, string $trigger): array
    {
        try {
            $result = $run($deadline, $trigger);
            $status = is_array($result) ? ($result['status'] ?? null) : null;
            if (!in_array($status, ['done', 'more', 'failed'], true)) {
                throw new UnexpectedValueException();
            }

            $summary = $result['summary'] ?? '';

            return [$status, is_string($summary) ? mb_substr($summary, 0, 200, 'UTF-8') : '', null];
        } catch (Throwable $e) {
            return ['failed', '', get_class($e)];
        }
    }

    private function signal(array $job, int $runId, string $status, int $failures, ?string $errorClass): void
    {
        $payload = [
            'job_id' => $job['id'],
            'plugin' => $job['plugin'],
            'run_id' => $runId,
            'consecutive_failures' => $failures,
            'error_class' => $errorClass,
        ];
        if ($status === 'failed') {
            $this->c['logger']->warning(sprintf('Foreman: job "%s" failed (run %d)', $job['id'], $runId), $payload);
        } else {
            $this->c['logger']->info(sprintf('Foreman: job "%s" recovered (run %d)', $job['id'], $runId), $payload);
        }

        $event = $status === 'failed' ? 'foreman.job.failed' : 'foreman.job.recovered';
        $this->c['dispatcher']->dispatch(new GenericEvent($payload), $event);
    }
}
