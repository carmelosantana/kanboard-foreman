<?php

namespace Kanboard\Plugin\Foreman\Model;

use Closure;
use PDO;
use Pimple\Container;

class Registry
{
    private Closure $clock;
    private Closure $coreEnabled;
    private ?array $jobs = null;

    public function __construct(private Container $c, callable $clock, callable $coreEnabled)
    {
        $this->clock = Closure::fromCallable($clock);
        $this->coreEnabled = Closure::fromCallable($coreEnabled);
    }

    /** @return array<string, array> */
    public function jobs(): array
    {
        if ($this->jobs !== null) {
            return $this->jobs;
        }

        $defaults = ['budget' => 10, 'hour' => null, 'link' => null];
        $jobs = [
            'core.cronjob' => Builtin::coreCronjob($this->c) + $defaults,
            'foreman.prune' => Builtin::prune($this->c, $this->clock) + $defaults,
        ];

        foreach ($this->c['hook']->getListeners('foreman:jobs') as $listener) {
            $descriptors = $listener();
            if (!is_array($descriptors) || !array_is_list($descriptors)) {
                continue;
            }

            foreach ($descriptors as $job) {
                if (!is_array($job)) {
                    continue;
                }

                $id = $job['id'] ?? null;
                $reason = $this->invalidReason($job, $jobs);
                if ($reason !== null) {
                    $this->c['logger']->warning(sprintf(
                        'Foreman: skipped job "%s" (%s)',
                        is_string($id) ? $id : '',
                        $reason
                    ));
                    continue;
                }

                $plugin = $job['plugin'] ?? null;
                $job['plugin'] = is_string($plugin) ? mb_substr($plugin, 0, 64, 'UTF-8') : 'unknown';
                $jobs[$id] = $job + $defaults;
            }
        }

        return $this->jobs = $jobs;
    }

    public function sync(): void
    {
        $jobs = $this->jobs();
        $pdo = $this->c['db']->getConnection();
        $now = ($this->clock)();
        $insert = 'INSERT INTO foreman_state (job_id, plugin, enabled, next_due_at) VALUES (?, ?, ?, ?)';
        $onConflict = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE job_id = job_id'
            : 'ON CONFLICT (job_id) DO NOTHING';
        $statement = $pdo->prepare($insert.' '.$onConflict);

        foreach ($jobs as $job) {
            $enabled = $job['id'] !== 'core.cronjob' || ($this->coreEnabled)();
            $statement->execute([$job['id'], $job['plugin'], (int) $enabled, $now]);
        }
    }

    private function invalidReason(array $job, array $jobs): ?string
    {
        $id = $job['id'] ?? null;
        $interval = $job['interval'] ?? null;
        $hour = $job['hour'] ?? null;

        return match (true) {
            !is_string($id) || !preg_match('/^[a-z0-9][a-z0-9:._-]{0,63}$/D', $id) => 'invalid id',
            isset($jobs[$id]) => 'duplicate id',
            !is_int($interval) || $interval < 60 => 'interval below 60',
            !is_callable($job['run'] ?? null) => 'run not callable',
            array_key_exists('hour', $job) && (!is_int($hour) || $hour < 0 || $hour > 23) => 'invalid hour',
            default => null,
        };
    }
}
