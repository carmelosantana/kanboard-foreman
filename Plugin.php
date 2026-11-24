<?php

namespace Kanboard\Plugin\Foreman;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Foreman\Console\RunCommand;
use Kanboard\Plugin\Foreman\Model\Foreman;
use Kanboard\Plugin\Foreman\Model\Registry;
use Kanboard\Plugin\Foreman\Model\Runner;
use Kanboard\Plugin\Foreman\Model\Settings;
use Kanboard\Plugin\Foreman\Trigger\Visit;
use Pimple\Container;
use Throwable;

class Plugin extends Base
{
    public function initialize(): void
    {
        $this->container['foreman'] = static fn (Container $c): Foreman => new Foreman($c);
        $this->container['foremanSettings'] = static fn (Container $c): Settings => new Settings($c, 'time');
        $this->container['foremanRegistry'] = static fn (Container $c): Registry =>
            new Registry($c, 'time', static fn (): bool => $c['foremanSettings']->coreCronjobEnabled());
        $this->container['foremanRunner'] = static fn (Container $c): Runner => new Runner($c, 'time');
        $this->container['foremanVisit'] = static fn (Container $c): Visit => new Visit($c);
        if (isset($this->container['cli'])) {
            $this->container['cli']->add(new RunCommand($this->container));
        }
        if (isset($this->container['hook'])) {
            $this->hook->on('template:layout:top', [
                'template' => 'Foreman:layout/top',
                'callable' => function (): array {
                    try {
                        $this->container['foremanVisit']->arm();
                    } catch (Throwable $e) {
                        $this->logger->error('Foreman: could not arm visit ('.get_class($e).')');
                    }

                    return [];
                },
            ]);
        }
    }

    public function getPluginName(): string
    {
        return 'Foreman';
    }

    public function getPluginDescription(): string
    {
        return 'Foreman is one small plugin that runs every suite job from one crontab line.';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-foreman';
    }

    public function getPluginLicense(): string
    {
        return 'MIT';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
