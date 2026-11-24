<?php

namespace Kanboard\Plugin\Foreman;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
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
