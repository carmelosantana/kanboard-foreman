<?php

namespace Kanboard\Plugin\Foreman;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Foreman\Console\RunCommand;
use Kanboard\Plugin\Foreman\Model\Foreman;
use Kanboard\Plugin\Foreman\Model\Registry;
use Kanboard\Plugin\Foreman\Model\Runner;
use Kanboard\Plugin\Foreman\Model\Settings;
use Pimple\Container;

class Plugin extends Base
{
    public function initialize(): void
    {
        $this->container['foreman'] = static fn (Container $c): Foreman => new Foreman($c);
        $this->container['foremanSettings'] = static fn (Container $c): Settings => new Settings($c, 'time');
        $this->container['foremanRegistry'] = static fn (Container $c): Registry =>
            new Registry($c, 'time', static fn (): bool => $c['foremanSettings']->coreCronjobEnabled());
        $this->container['foremanRunner'] = static fn (Container $c): Runner => new Runner($c, 'time');
        if (isset($this->container['cli'])) {
            $this->container['cli']->add(new RunCommand($this->container));
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
