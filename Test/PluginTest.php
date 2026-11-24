<?php

namespace Kanboard\Plugin\Foreman\Test;

use Kanboard\Plugin\Foreman\Plugin;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

class PluginTest extends TestCase
{
    public function testVersionsAgree(): void
    {
        $manifest = $this->readManifest();
        $plugin = new Plugin(new Container());

        $this->assertSame('1.0.0', $manifest['version']);
        $this->assertSame($manifest['version'], $plugin->getPluginVersion());
    }

    public function testManifest(): void
    {
        $manifest = $this->readManifest();

        $this->assertSame('>=1.2.47', $manifest['kanboard_version']);
        $this->assertSame('>=8.4', $manifest['php_version']);
        $this->assertSame($manifest['kanboard_version'], (new Plugin(new Container()))->getCompatibleVersion());
        $this->assertContains('Harbor', array_column($manifest['recommends'], 'plugin'));
        $this->assertNotContains('Harbor', array_column($manifest['requires'] ?? [], 'plugin'));
    }

    private function readManifest(): array
    {
        $path = __DIR__.'/../plugin.json';
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
