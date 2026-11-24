<p><?= $this->url->link(t('Back to Jobs'), 'JobsController', 'index', ['plugin' => 'Foreman']) ?></p>
<h2><?= t('Run history for %s', $jobId) ?></h2>
<?php if ($runs === []): ?>
    <p><?= t('No runs recorded.') ?></p>
<?php else: ?>
    <div class="table-list">
        <table class="table-striped" data-slot="table">
            <thead>
                <tr>
                    <th scope="col"><?= t('Started') ?></th>
                    <th scope="col"><?= t('Finished') ?></th>
                    <th scope="col"><?= t('Trigger') ?></th>
                    <th scope="col"><?= t('Status') ?></th>
                    <th scope="col"><?= t('Summary') ?></th>
                    <th scope="col"><?= t('Error class') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><?= $this->dt->datetime($run['started_at']) ?></td>
                    <td><?= $run['finished_at'] === null ? '—' : $this->dt->datetime($run['finished_at']) ?></td>
                    <td><?= $this->text->e($run['trigger']) ?></td>
                    <td><span class="foreman-status" data-slot="badge" data-state="<?= $this->text->e($run['status']) ?>"><?= $this->text->e($run['status']) ?></span></td>
                    <td><?= $this->text->e($run['summary'] ?? '') ?></td>
                    <td><?= $this->text->e($run['error_class'] ?? '') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
