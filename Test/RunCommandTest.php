<?php

namespace Kanboard\Plugin\Foreman\Test;

use Kanboard\Core\Plugin\Hook;
use Kanboard\Plugin\Foreman\Console\RunCommand;
use Kanboard\Plugin\Foreman\Model\Lease;
use Kanboard\Plugin\Foreman\Model\Registry;
use Kanboard\Plugin\Foreman\Model\Runner;
use Kanboard\Plugin\Foreman\Model\Settings;
use Kanboard\Plugin\Foreman\Plugin;
use KanboardTests\units\Base;
use Symfony\Component\Console\Tester\CommandTester;
use function Kanboard\Plugin\Foreman\Schema\version_1;

require_once 'tests/units/Base.php';

class RunCommandTest extends Base
{
    private const NOW = 1795395600; // 2026-11-23 01:00 UTC
    private int $now = self::NOW;
    private array $jobs = [];
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());

        $this->container['hook'] = new Hook();
        $this->container['hook']->on('foreman:jobs', fn (): array => $this->jobs);
        $clock = fn (): int => $this->now;
        $this->container['foremanSettings'] = new Settings($this->container, $clock);
        $this->container['foremanRegistry'] = new Registry($this->container, $clock, static fn (): bool => false);
        $this->container['foremanRunner'] = new Runner($this->container, $clock);
    }

    public function testPluginRegistersCommand(): void
    {
        (new Plugin($this->container))->initialize();

        $this->assertInstanceOf(RunCommand::class, $this->container['cli']->get('foreman:run'));
    }

    public function testRunsOneCronTickAndStoresHeartbeat(): void
    {
        $this->addJob('a', static fn (): array => ['status' => 'done', 'summary' => 'updated']);
        $this->addJob('b', static fn (): array => ['status' => 'more']);
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute([]));
        $this->assertSame("a: done — updated\nb: more\n", $tester->getDisplay(true));
        $this->assertSame([
            ['a', self::NOW + 50, 'cli'],
            ['b', self::NOW + 50, 'cli'],
        ], $this->calls);
        $this->assertSame((string) self::NOW, $this->container['configModel']->getOption('foreman_cli_last_seen'));
    }

    public function testAnyFailedJobMakesTheCommandFail(): void
    {
        $this->addJob('a', static fn (): array => ['status' => 'failed']);
        $this->addJob('b', static fn (): array => ['status' => 'done']);
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute([]));
        $this->assertSame("a: failed\nb: done\n", $tester->getDisplay(true));
    }

    public function testUnknownJobIsRejected(): void
    {
        $tester = $this->tester();

        $this->assertSame(2, $tester->execute(['--job' => 'unknown']));
        $this->assertSame("Unknown or disabled job: unknown\n", $tester->getDisplay(true));
    }

    public function testSelectedJobGetsAFreshDeadlineUntilItFinishes(): void
    {
        $this->addJob('other', static fn (): array => ['status' => 'done']);
        $this->addJob('x', function (): array {
            $this->now += 50;

            return ['status' => count($this->calls) < 3 ? 'more' : 'done'];
        });
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['--job' => 'x']));
        $this->assertSame("x: more\nx: more\nx: done\n", $tester->getDisplay(true));
        $this->assertSame([
            ['x', self::NOW + 50, 'cli'],
            ['x', self::NOW + 100, 'cli'],
            ['x', self::NOW + 150, 'cli'],
        ], $this->calls);
    }

    public function testDisabledJobIsRejected(): void
    {
        $this->addJob('x', static fn (): array => ['status' => 'done']);
        $this->container['foremanRegistry']->sync();
        $this->container['foremanSettings']->setJobEnabled('x', false);
        $tester = $this->tester();

        $this->assertSame(2, $tester->execute(['--job' => 'x']));
        $this->assertSame("Unknown or disabled job: x\n", $tester->getDisplay(true));
        $this->assertSame([], $this->calls);
    }

    public function testSelectedJobWithHeldLeaseIsStillEnabled(): void
    {
        $this->addJob('x', static fn (): array => ['status' => 'done']);
        $this->container['foremanRegistry']->sync();
        $lease = new Lease($this->container['db']->getConnection(), fn (): int => $this->now);
        $this->assertTrue($lease->claim('x', self::NOW + 60));
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['--job' => 'x']));
        $this->assertSame('', $tester->getDisplay(true));
        $this->assertSame([], $this->calls);
    }

    private function addJob(string $id, callable $run): void
    {
        $this->jobs[] = [
            'id' => $id,
            'plugin' => 'Example',
            'title' => $id,
            'interval' => 60,
            'run' => function (int $deadline, string $trigger) use ($id, $run): array {
                $this->calls[] = [$id, $deadline, $trigger];

                return $run();
            },
        ];
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new RunCommand($this->container, fn (): int => $this->now));
    }
}
