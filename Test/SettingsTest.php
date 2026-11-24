<?php

namespace Kanboard\Plugin\Foreman\Test;

use Kanboard\Core\Plugin\Hook;
use Kanboard\Plugin\Foreman\Model\Settings;
use Kanboard\Plugin\Foreman\Plugin;
use KanboardTests\units\Base;
use function Kanboard\Plugin\Foreman\Schema\version_1;

require_once 'tests/units/Base.php';

class SettingsTest extends Base
{
    private const NOW = 1700000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['hook'] = new Hook();
    }

    public function testDefaultsWithoutAnAdapterOrCliHeartbeat(): void
    {
        $settings = $this->settings();

        $this->assertNull($settings->host());
        $this->assertNull($settings->cliLastSeen());
        $this->assertSame('auto', $settings->visitMode());
        $this->assertTrue($settings->visitAllowed());
        $this->assertFalse($settings->isForced('visit_fallback'));
        $this->assertFalse($settings->coreCronjobEnabled());
    }

    public function testAutoModeUsesAStrictTenMinuteThreshold(): void
    {
        $settings = $this->settings();
        $config = $this->container['configModel'];
        $config->save(['foreman_cli_last_seen' => (string) (self::NOW - 599)]);
        $this->assertFalse($settings->visitAllowed());

        $config->save(['foreman_cli_last_seen' => (string) (self::NOW - 600)]);
        $this->assertFalse($settings->visitAllowed());

        $config->save(['foreman_cli_last_seen' => (string) (self::NOW - 601)]);
        $this->assertTrue($settings->visitAllowed());
    }

    public function testMarkCliSeenPersistsTheInjectedTime(): void
    {
        $settings = $this->settings();
        $settings->markCliSeen();

        $this->assertSame(self::NOW, $this->settings()->cliLastSeen());
        $this->assertFalse($settings->visitAllowed());

        $this->container['configModel']->save(['foreman_visit_mode' => 'always']);
        $this->assertTrue($settings->visitAllowed());
    }

    public function testHostForcesVisitModeAndProvidesAnOverridableCoreDefault(): void
    {
        $adapter = ['visit_fallback' => 'never', 'core_cronjob_default' => true];
        $this->container['hook']->on('foreman:host', fn (): array => $adapter);
        $this->container['hook']->on('foreman:host', fn (): array => ['visit_fallback' => 'always']);
        $config = $this->container['configModel'];
        $config->save(['foreman_visit_mode' => 'always']);
        $settings = $this->settings();

        $this->assertSame($adapter, $settings->host());
        $this->assertSame('never', $settings->visitMode());
        $this->assertFalse($settings->visitAllowed());
        $this->assertTrue($settings->isForced('visit_fallback'));
        $this->assertFalse($settings->isForced('core_cronjob_default'));
        $this->assertTrue($settings->coreCronjobEnabled());

        $config->save(['foreman_core_cronjob' => '0']);
        $this->assertFalse($settings->coreCronjobEnabled());
    }

    public function testEmptyAdapterAndInvalidSavedModeFallBackToDefaults(): void
    {
        $this->container['hook']->on('foreman:host', fn (): array => []);
        $this->container['configModel']->save(['foreman_visit_mode' => 'sometimes']);
        $settings = $this->settings();

        $this->assertNull($settings->host());
        $this->assertSame('auto', $settings->visitMode());
    }

    public function testPluginRegistryReadsCoreSettingAndJobTogglePersists(): void
    {
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $pdo = $this->container['db']->getConnection();
        version_1($pdo);
        $this->container['configModel']->save(['foreman_core_cronjob' => '1']);
        (new Plugin($this->container))->initialize();

        $settings = $this->container['foremanSettings'];
        $this->assertInstanceOf(Settings::class, $settings);
        $registry = $this->container['foremanRegistry'];
        $registry->sync();
        $query = "SELECT enabled FROM foreman_state WHERE job_id = 'core.cronjob'";
        $this->assertSame(1, (int) $pdo->query($query)->fetchColumn());

        $settings->setJobEnabled('core.cronjob', false);
        $registry->sync();
        $this->assertSame(0, (int) $pdo->query($query)->fetchColumn());

        $settings->setJobEnabled('core.cronjob', true);
        $this->assertSame(1, (int) $pdo->query($query)->fetchColumn());
    }

    private function settings(): Settings
    {
        return new Settings($this->container, fn (): int => self::NOW);
    }
}
