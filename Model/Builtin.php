<?php

namespace Kanboard\Plugin\Foreman\Model;

use Pimple\Container;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class Builtin
{
    public static function coreCronjob(Container $c): array
    {
        return [
            'id' => 'core.cronjob',
            'plugin' => 'Kanboard',
            'title' => 'Core cronjob',
            'interval' => 86400,
            'hour' => 8,
            'run' => static function () use ($c): array {
                $c['cli']->find('cronjob')->run(new ArrayInput(['command' => 'cronjob']), new NullOutput());

                return ['status' => 'done'];
            },
        ];
    }

    public static function prune(Container $c, callable $clock): array
    {
        return [
            'id' => 'foreman.prune',
            'plugin' => 'Foreman',
            'title' => 'Prune run history',
            'interval' => 86400,
            'hour' => 3,
            'run' => static function () use ($c, $clock): array {
                $statement = $c['db']->getConnection()->prepare('DELETE FROM foreman_runs WHERE started_at < ?');
                $statement->execute([$clock() - 30 * 86400]);

                return ['status' => 'done', 'summary' => 'pruned '.$statement->rowCount()];
            },
        ];
    }
}
