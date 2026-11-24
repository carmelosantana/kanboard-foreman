<?php if (($foremanHealth['failing'] ?? 0) > 0): ?>
    <div class="alert alert-error" data-slot="callout">
        <?= $this->url->link(t('Foreman: %d job(s) failing', $foremanHealth['failing']), 'JobsController', 'index', ['plugin' => 'Foreman']) ?>
    </div>
<?php endif ?>
<?php if (isset($foremanHealth['stale_minutes'])): ?>
    <div class="alert" data-slot="callout">
        <?= $this->url->link(t('Foreman: no job has run for %d minutes', $foremanHealth['stale_minutes']), 'JobsController', 'index', ['plugin' => 'Foreman']) ?>
    </div>
<?php endif ?>
