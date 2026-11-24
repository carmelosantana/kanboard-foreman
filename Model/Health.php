<?php

namespace Kanboard\Plugin\Foreman\Model;

use Closure;
use PDO;
use Pimple\Container;

class Health
{
    private Closure $clock;

    public function __construct(private Container $c, callable $clock)
    {
        $this->clock = Closure::fromCallable($clock);
    }

    /** @return array{failing: int, stale_minutes: ?int} */
    public function summary(): array
    {
        $jobs = $this->c['foremanRegistry']->jobs();
        $states = $this->c['db']->getConnection()->query(
            'SELECT job_id, last_status, last_finished_at, next_due_at FROM foreman_state WHERE enabled = 1'
        );
        $failing = 0;
        $intervals = [];
        $finished = [];
        $due = [];

        while ($state = $states->fetch(PDO::FETCH_ASSOC)) {
            $job = $jobs[$state['job_id']] ?? null;
            if ($job === null) {
                continue;
            }

            $failing += (int) ($state['last_status'] === 'failed');
            $intervals[] = $job['interval'];
            $due[] = (int) $state['next_due_at'];
            if ($state['last_finished_at'] !== null) {
                $finished[] = (int) $state['last_finished_at'];
            }
        }

        $staleMinutes = null;
        if ($intervals !== []) {
            // Before the first finish, the initial due time is the oldest available timestamp.
            $age = ($this->clock)() - ($finished === [] ? min($due) : max($finished));
            if ($age > 2 * min($intervals)) {
                $staleMinutes = intdiv($age, 60);
            }
        }

        return ['failing' => $failing, 'stale_minutes' => $staleMinutes];
    }
}
