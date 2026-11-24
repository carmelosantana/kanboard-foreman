<?php if ($this->user->isAdmin()): ?>
    <li <?= $this->app->checkMenuSelection('JobsController', '', 'Foreman') ?>>
        <?= $this->url->link(t('Jobs'), 'JobsController', 'index', ['plugin' => 'Foreman']) ?>
    </li>
<?php endif ?>
