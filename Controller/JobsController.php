<?php

namespace Kanboard\Plugin\Foreman\Controller;

use Closure;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\PageNotFoundException;
use Kanboard\Plugin\Foreman\Model\RunLog;
use PDO;
use Pimple\Container;
use Throwable;

class JobsController extends BaseController
{
    private Closure $clock;

    public function __construct(Container $container, ?callable $clock = null)
    {
        parent::__construct($container);
        $this->clock = Closure::fromCallable($clock ?? 'time');
    }

    public function index(): void
    {
        $registry = $this->foremanRegistry;
        $registry->sync();
        $jobs = $registry->jobs();
        $states = $this->db->getConnection()->query('SELECT * FROM foreman_state')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $now = ($this->clock)();
        $lastSeen = $this->foremanSettings->cliLastSeen();
        $schedules = $this->foremanSettings->host()['schedules'] ?? null;
        $scheduleRows = [];
        if (is_callable($schedules)) {
            try {
                $scheduleRows = $schedules();
            } catch (Throwable $e) {
                $this->logger->error('Foreman: could not read host schedules ('.get_class($e).')');
                $this->flash->failure(t('Unable to read host schedules.'));
            }
        }

        $this->renderSettings('index', [
            'title' => t('Jobs'),
            'jobs' => $jobs,
            'states' => $states,
            'cliMinutes' => $lastSeen === null ? null : intdiv(max(0, $now - $lastSeen), 60),
            'staleMinutes' => $this->container['foremanHealth']->summary()['stale_minutes'],
            'visitForced' => $this->foremanSettings->isForced('visit_fallback'),
            'schedules' => is_array($scheduleRows) ? $scheduleRows : [],
            'values' => [
                'foreman_visit_mode' => $this->foremanSettings->visitMode(),
                'foreman_core_cronjob' => (string) (int) $this->foremanSettings->coreCronjobEnabled(),
            ],
        ]);
    }

    public function runs(): void
    {
        $id = $this->request->getStringParam('job_id');
        if (!is_string($id) || !isset($this->foremanRegistry->jobs()[$id])) {
            throw new PageNotFoundException();
        }

        $state = $this->jobState($id);
        $now = ($this->clock)();
        $runs = (new RunLog($this->container, $this->clock))->recent($id);
        foreach ($runs as $index => &$run) {
            if ($run['finished_at'] === null) {
                // State only tracks the current lease, so it cannot keep an older run active.
                $active = $index === 0 && (int) ($state['lease_until'] ?? 0) >= $now;
                $run['status'] = $active ? 'running' : 'interrupted';
            }
        }
        unset($run);

        $this->renderSettings('runs', ['title' => t('Job runs'), 'jobId' => $id, 'runs' => $runs]);
    }

    public function run(): void
    {
        if (!$this->checkPost()) {
            return;
        }

        $id = $this->request->getRawValue('job_id');
        if (!(int) ($this->jobState($id)['enabled'] ?? 0)) {
            $this->flash->failure(t('Unknown or disabled job.'));
            $this->redirectToJobs();
            return;
        }

        $runJob = $this->foremanSettings->host()['run_job'] ?? null;
        if (is_callable($runJob)) {
            $accepted = false;
            try {
                $result = $runJob($id);
                $accepted = ($result['accepted'] ?? false) === true;
            } catch (Throwable $e) {
                $this->logger->error('Foreman: host rejected the job run ('.get_class($e).')');
            }
            if ($accepted) {
                $this->flash->success(t('Job queued.'));
            } else {
                $this->flash->failure(t('The host could not queue the job.'));
            }
        } else {
            $results = $this->foremanRunner->tick('manual', ($this->clock)() + 10, $id);
            if ($results === []) {
                $this->flash->failure(t('The job could not be started.'));
            } elseif ($results[0]['status'] === 'failed') {
                $this->flash->failure(t('The job failed. See its run history.'));
            } else {
                $this->flash->success(t('Job ran. See its run history.'));
            }
        }

        $this->redirectToJobs();
    }

    public function toggle(): void
    {
        if (!$this->checkPost()) {
            return;
        }

        $id = $this->request->getRawValue('job_id');
        $enabled = $this->request->getRawValue('enabled');
        if (!in_array($enabled, ['0', '1'], true) || $this->jobState($id) === null) {
            $this->flash->failure(t('Invalid job or enabled setting.'));
        } elseif ($id === 'core.cronjob') {
            $this->saveSettings(['foreman_core_cronjob' => $enabled]);
        } else {
            $this->foremanSettings->setJobEnabled($id, $enabled === '1');
            $this->flash->success(t('Job updated.'));
        }

        $this->redirectToJobs();
    }

    public function save(): void
    {
        if (!$this->checkPost()) {
            return;
        }

        $mode = $this->request->getRawValue('foreman_visit_mode');
        $core = $this->request->getRawValue('foreman_core_cronjob');
        $forced = $this->foremanSettings->isForced('visit_fallback');
        $validMode = ($forced && $mode === null) || in_array($mode, ['auto', 'always', 'never'], true);

        if (!$validMode || !in_array($core, ['0', '1'], true)) {
            $this->flash->failure(t('Invalid job settings.'));
        } else {
            $values = ['foreman_core_cronjob' => $core];
            if (!$forced) {
                $values['foreman_visit_mode'] = $mode;
            }
            $this->saveSettings($values);
        }

        $this->redirectToJobs();
    }

    private function saveSettings(array $values): void
    {
        $this->foremanRegistry->sync();
        if ($this->configModel->save($values)) {
            $this->foremanSettings->setJobEnabled('core.cronjob', $values['foreman_core_cronjob'] === '1');
            $this->flash->success(t('Settings saved successfully.'));
        } else {
            $this->flash->failure(t('Unable to save your settings.'));
        }
    }

    private function checkPost(): bool
    {
        if (!$this->request->isPost()) {
            $this->response->withHeader('Allow', 'POST')->text(t('Use POST for this action.'), 405);
            return false;
        }

        $this->checkCSRFForm();
        return true;
    }

    private function jobState(mixed $id): ?array
    {
        $registry = $this->foremanRegistry;
        if (!is_string($id) || !isset($registry->jobs()[$id])) {
            return null;
        }

        $registry->sync();
        $statement = $this->db->getConnection()->prepare('SELECT * FROM foreman_state WHERE job_id = ?');
        $statement->execute([$id]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function renderSettings(string $view, array $parameters): void
    {
        $this->response->html($this->helper->layout->config('Foreman:jobs/'.$view, $parameters));
    }

    private function redirectToJobs(): void
    {
        $this->response->withStatusCode(303)->redirect(
            $this->helper->url->to('JobsController', 'index', ['plugin' => 'Foreman'])
        );
    }
}
