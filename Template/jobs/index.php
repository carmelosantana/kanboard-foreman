<?php
$params = ['plugin' => 'Foreman'];
$runsUrl = $this->url->href('JobsController', 'runs', $params);
$runsQuery = str_contains($runsUrl, '?') ? '&amp;' : '?';
?>
<p><?= $cliMinutes === null ? t('Page visits only') : t('Cron last seen %d min ago', $cliMinutes) ?></p>
<pre><code>* * * * * cd /var/www/app &amp;&amp; php cli foreman:run</code></pre>
<?php if ($staleMinutes !== null): ?>
    <p class="alert" data-slot="callout"><?= t('Foreman: no job has run for %d minutes', $staleMinutes) ?></p>
<?php endif ?>

<form method="post" action="<?= $this->url->href('JobsController', 'save', $params) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Page-visit fallback'), 'foreman_visit_mode') ?>
    <?= $this->form->select('foreman_visit_mode', ['auto' => t('Auto'), 'always' => t('Always'), 'never' => t('Never')], $values, [], $visitForced ? ['disabled'] : []) ?>
    <?php if ($visitForced): ?>
        <span><?= t('Managed by the host') ?></span>
    <?php endif ?>
    <?= $this->form->label(t('Run Kanboard’s core cronjob through Foreman'), 'foreman_core_cronjob') ?>
    <?= $this->form->select('foreman_core_cronjob', [0 => t('Disabled'), 1 => t('Enabled')], $values) ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue" data-slot="button"><?= t('Save') ?></button>
    </div>
</form>

<div class="table-list">
    <table class="table-striped" data-slot="table">
        <thead>
            <tr>
                <th scope="col"><?= t('Job') ?></th>
                <th scope="col"><?= t('Plugin') ?></th>
                <th scope="col"><?= t('Cadence') ?></th>
                <th scope="col"><?= t('Last run') ?></th>
                <th scope="col"><?= t('Status') ?></th>
                <th scope="col"><?= t('Next due') ?></th>
                <th scope="col"><?= t('Actions') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
            <?php
            $id = $job['id'];
            $state = $states[$id];
            $enabled = (bool) $state['enabled'];
            $interval = $job['interval'];
            $cadence = match (true) {
                $job['hour'] !== null => t('Daily at %02d:00', $job['hour']),
                $interval % 3600 === 0 => t('Every %d hours', intdiv($interval, 3600)),
                $interval % 60 === 0 => t('Every %d minutes', intdiv($interval, 60)),
                default => t('Every %d seconds', $interval),
            };
            $link = $job['link'];
            ?>
            <tr>
                <th scope="row">
                    <?= $this->text->e(is_string($job['title'] ?? null) ? $job['title'] : $id) ?>
                    <br><code><?= $this->text->e($id) ?></code>
                </th>
                <td><?= $this->text->e($job['plugin']) ?></td>
                <td><?= $cadence ?></td>
                <td><?= $state['last_finished_at'] === null ? '—' : $this->dt->datetime($state['last_finished_at']) ?></td>
                <td>
                    <?php if ($state['last_status'] !== null): ?>
                        <span class="foreman-status" data-slot="badge" data-state="<?= $this->text->e($state['last_status']) ?>"><?= $this->text->e($state['last_status']) ?></span>
                    <?php else: ?>
                        —
                    <?php endif ?>
                </td>
                <td><?= $enabled ? $this->dt->datetime($state['next_due_at']) : '—' ?></td>
                <td>
                    <a href="<?= $runsUrl.$runsQuery.'job_id='.rawurlencode($id) ?>"><?= t('Run history') ?></a>
                    <?php if (is_array($link) && is_string($link['controller'] ?? null) && is_string($link['action'] ?? null)): ?>
                        <?= $this->url->link(t('Details'), $link['controller'], $link['action'],
                            (is_array($link['params'] ?? null) ? $link['params'] : []) + ['plugin' => $link['plugin'] ?? $job['plugin']]) ?>
                    <?php endif ?>
                    <form method="post" action="<?= $this->url->href('JobsController', 'run', $params) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="job_id" value="<?= $this->text->e($id) ?>">
                        <button type="submit" class="btn" data-slot="button"<?= $enabled ? '' : ' disabled' ?>><?= t('Run now') ?></button>
                    </form>
                    <form method="post" action="<?= $this->url->href('JobsController', 'toggle', $params) ?>">
                        <?= $this->form->csrf() ?>
                        <input type="hidden" name="job_id" value="<?= $this->text->e($id) ?>">
                        <button type="submit" name="enabled" value="<?= $enabled ? '0' : '1' ?>" class="btn" data-slot="button"><?= $enabled ? t('Disable') : t('Enable') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<?php if ($schedules !== []): ?>
    <h2><?= t('Host schedules') ?></h2>
    <ul>
        <?php foreach ($schedules as $row): ?>
            <?php $fields = array_filter((array) $row, 'is_scalar'); ?>
            <li><?= $this->text->e(implode(' · ', $fields)) ?></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
