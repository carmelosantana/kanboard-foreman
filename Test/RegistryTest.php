<?php

namespace Kanboard\Plugin\Foreman\Test;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Plugin\Foreman\Model\Builtin;
use Kanboard\Plugin\Foreman\Model\Registry;
use Kanboard\Plugin\Foreman\Plugin;
use KanboardTests\units\Base;
use PDO;
use PDOStatement;
use Pimple\Container;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use function Kanboard\Plugin\Foreman\Schema\version_1;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class RegistryTest extends Base
{
    use ReleasesConnection;

    private const NOW = 1700000000;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['hook'] = new Hook();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $this->pdo = $this->container['db']->getConnection();
        version_1($this->pdo);
    }

    public function testPluginRegistersRegistryLazily(): void
    {
        $container = new Container();
        (new Plugin($container))->initialize();

        $this->assertTrue(isset($container['foremanRegistry']));
        $container['hook'] = new Hook();
        $container['db'] = $this->container['db'];
        $this->assertInstanceOf(Registry::class, $container['foremanRegistry']);
    }

    public function testCollectsJobsAndAppliesDefaults(): void
    {
        $this->container['hook']->on('foreman:jobs', fn () => [$this->job('deed.refresh')]);
        $this->container['hook']->on('foreman:jobs', fn () => [
            array_replace($this->job('vault:purge'), [
                'plugin' => 'Vault',
                'budget' => 5,
                'hour' => 4,
                'link' => ['controller' => 'Vault'],
            ]),
        ]);
        $this->container['hook']->on('foreman:jobs', fn () => 'ignored');

        $jobs = $this->registry()->jobs();

        $this->assertEqualsCanonicalizing(
            ['deed.refresh', 'vault:purge', 'core.cronjob', 'foreman.prune'],
            array_keys($jobs)
        );
        $this->assertSame(10, $jobs['deed.refresh']['budget']);
        $this->assertNull($jobs['deed.refresh']['hour']);
        $this->assertNull($jobs['deed.refresh']['link']);
        $this->assertSame(5, $jobs['vault:purge']['budget']);
        $this->assertSame(4, $jobs['vault:purge']['hour']);
        $this->assertSame(['controller' => 'Vault'], $jobs['vault:purge']['link']);
        $this->assertSame(86400, $jobs['core.cronjob']['interval']);
        $this->assertSame(8, $jobs['core.cronjob']['hour']);
        $this->assertSame(86400, $jobs['foreman.prune']['interval']);
        $this->assertSame(3, $jobs['foreman.prune']['hour']);
    }

    public function testSkipsInvalidJobsAndKeepsTheFirstDuplicate(): void
    {
        $logger = new class extends AbstractLogger {
            public array $warnings = [];

            public function log($level, $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = $message;
                }
            }
        };
        $this->container['logger'] = $logger;
        $this->container['hook']->on('foreman:jobs', fn () => [$this->job('deed.refresh')]);
        $this->container['hook']->on('foreman:jobs', fn () => [
            $this->job('Bad Id'),
            array_replace($this->job('deed.refresh'), ['plugin' => 'Duplicate']),
            array_replace($this->job('fast'), ['interval' => 59]),
            array_replace($this->job('no-run'), ['run' => 'not_a_callable']),
            array_replace($this->job('hour-too-high'), ['hour' => 24]),
            array_replace($this->job('hour-too-low'), ['hour' => -1]),
            array_replace($this->job('hour-string'), ['hour' => '8']),
            array_replace($this->job('hour-null'), ['hour' => null]),
            array_replace($this->job('midnight'), ['hour' => 0]),
        ]);

        $jobs = $this->registry()->jobs();

        $this->assertEqualsCanonicalizing(
            ['deed.refresh', 'core.cronjob', 'foreman.prune', 'hour-null', 'midnight'],
            array_keys($jobs)
        );
        $this->assertSame('Deed', $jobs['deed.refresh']['plugin']);
        $this->assertNull($jobs['hour-null']['hour']);
        $this->assertSame(0, $jobs['midnight']['hour']);
        $this->assertSame([
            'Foreman: skipped job "Bad Id" (invalid id)',
            'Foreman: skipped job "deed.refresh" (duplicate id)',
            'Foreman: skipped job "fast" (interval below 60)',
            'Foreman: skipped job "no-run" (run not callable)',
            'Foreman: skipped job "hour-too-high" (invalid hour)',
            'Foreman: skipped job "hour-too-low" (invalid hour)',
            'Foreman: skipped job "hour-string" (invalid hour)',
        ], $logger->warnings);
    }

    public function testJobsValidatesAndWarnsOncePerRegistry(): void
    {
        $logger = $this->getMockBuilder(AbstractLogger::class)->onlyMethods(['log'])->getMock();
        $logger->expects($this->once())->method('log')
            ->with('warning', 'Foreman: skipped job "Bad Id" (invalid id)', []);
        $this->container['logger'] = $logger;
        $this->container['hook']->on('foreman:jobs', fn () => [$this->job('Bad Id')]);

        $registry = $this->registry();

        $this->assertSame($registry->jobs(), $registry->jobs());
    }

    public function testSyncInsertsMissingStateAndPreservesExistingRows(): void
    {
        $this->container['hook']->on('foreman:jobs', fn () => [$this->job('deed.refresh')]);
        $registry = $this->registry();
        $registry->sync();
        $rows = $this->state();

        $this->assertSame(['core.cronjob', 'deed.refresh', 'foreman.prune'], array_keys($rows));
        $this->assertSame(0, (int) $rows['core.cronjob']['enabled']);
        $this->assertSame(1, (int) $rows['deed.refresh']['enabled']);
        $this->assertSame(1, (int) $rows['foreman.prune']['enabled']);
        $this->assertSame('Deed', $rows['deed.refresh']['plugin']);
        $this->assertSame(self::NOW, (int) $rows['deed.refresh']['next_due_at']);

        $registry->sync();
        $this->assertSame($rows, $this->state());

        $this->pdo->prepare('UPDATE foreman_state SET enabled = 0, next_due_at = ? WHERE job_id = ?')
            ->execute([self::NOW + 120, 'deed.refresh']);
        $rows = $this->state();
        $registry->sync();
        $this->assertSame($rows, $this->state());

        $this->pdo->exec("DELETE FROM foreman_state WHERE job_id = 'foreman.prune'");
        $registry->sync();
        $this->assertSame($rows, $this->state());

        $this->container['hook'] = new Hook();
        $registry = $this->registry();
        $this->assertArrayNotHasKey('deed.refresh', $registry->jobs());
        $registry->sync();
        $this->assertSame($rows, $this->state());
    }

    public function testSyncExecutesNoInsertsWhenAllJobsHaveState(): void
    {
        $this->container['hook']->on('foreman:jobs', fn () => [$this->job('deed.refresh')]);
        $registry = $this->registry();
        $registry->sync();

        $inserts = [];
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RegistryInsertSpy::class, [
            static function (string $jobId) use (&$inserts): void {
                $inserts[] = $jobId;
            },
        ]]);

        $registry->sync();

        $this->assertSame([], $inserts);
    }

    /** @dataProvider pluginNames */
    public function testSyncStoresNormalizedPluginNames(array $pluginField, string $expected): void
    {
        $job = array_diff_key($this->job('deed.refresh'), ['plugin' => true]) + $pluginField;
        $this->container['hook']->on('foreman:jobs', fn () => [$job]);
        $registry = $this->registry();

        $registry->sync();

        $this->assertSame($expected, $this->state()['deed.refresh']['plugin']);
        $this->assertSame($expected, $registry->jobs()['deed.refresh']['plugin']);
    }

    public static function pluginNames(): array
    {
        return [
            'missing' => [[], 'unknown'],
            'non-string' => [['plugin' => 123], 'unknown'],
            'long' => [['plugin' => str_repeat('x', 100)], str_repeat('x', 64)],
            'multibyte' => [['plugin' => str_repeat('é', 100)], str_repeat('é', 64)],
        ];
    }

    public function testSyncCanEnableCoreCronjobOnInsert(): void
    {
        $this->registry(true)->sync();

        $this->assertSame(1, (int) $this->state()['core.cronjob']['enabled']);
    }

    public function testSyncUsesTheApplicationTimezoneForFirstDue(): void
    {
        $this->container['configModel']->save(['application_timezone' => 'America/New_York']);
        $tz = new DateTimeZone('America/New_York');
        $now = (new DateTimeImmutable('2026-11-23 06:00', $tz))->getTimestamp();
        $due = (new DateTimeImmutable('2026-11-23 08:00', $tz))->getTimestamp();
        $registry = new Registry($this->container, fn (): int => $now, fn (): bool => true);

        $registry->sync();

        $this->assertSame($due, (int) $this->state()['core.cronjob']['next_due_at']);
    }

    public function testPruneDeletesRunsOlderThanThirtyDays(): void
    {
        $trigger = DB_DRIVER === 'mysql' ? '`trigger`' : '"trigger"';
        $insert = $this->pdo->prepare("INSERT INTO foreman_runs (job_id, $trigger, started_at) VALUES (?, ?, ?)");
        foreach ([31, 30, 29] as $days) {
            $insert->execute(['deed.refresh', 'cli', self::NOW - $days * 86400]);
        }

        $job = Builtin::prune($this->container, fn (): int => self::NOW);

        $this->assertSame(['status' => 'done', 'summary' => 'pruned 1'], $job['run'](self::NOW + 10, 'cli'));
        $remaining = $this->pdo->query('SELECT started_at FROM foreman_runs ORDER BY started_at')
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([self::NOW - 30 * 86400, self::NOW - 29 * 86400], array_map('intval', $remaining));
    }

    public function testCoreCronjobInvokesTheConsoleCommand(): void
    {
        $command = new class('cronjob') extends Command {
            public ?InputInterface $input = null;
            public ?OutputInterface $output = null;

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->input = $input;
                $this->output = $output;

                return self::SUCCESS;
            }
        };
        $this->container['cli']->add($command);
        $job = Builtin::coreCronjob($this->container);

        $this->assertSame(['status' => 'done'], $job['run'](self::NOW + 10, 'cli'));
        $this->assertInstanceOf(ArrayInput::class, $command->input);
        $this->assertInstanceOf(NullOutput::class, $command->output);
    }

    public function testCoreCronjobReportsNonzeroExit(): void
    {
        $command = new class('cronjob') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return self::FAILURE;
            }
        };
        $this->container['cli']->add($command);
        $job = Builtin::coreCronjob($this->container);

        $this->assertSame(['status' => 'failed', 'summary' => 'exit 1'], $job['run'](self::NOW + 10, 'cli'));
    }

    private function registry(bool $coreEnabled = false): Registry
    {
        return new Registry($this->container, fn (): int => self::NOW, fn (): bool => $coreEnabled);
    }

    private function job(string $id): array
    {
        return [
            'id' => $id,
            'plugin' => 'Deed',
            'title' => 'Refresh',
            'interval' => 60,
            'run' => static fn (): array => ['status' => 'done'],
        ];
    }

    private function state(): array
    {
        return $this->pdo->query('SELECT * FROM foreman_state ORDER BY job_id')
            ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    }
}

class RegistryInsertSpy extends PDOStatement
{
    protected function __construct(private Closure $recordInsert)
    {
    }

    public function execute(?array $params = null): bool
    {
        if (str_starts_with($this->queryString, 'INSERT INTO foreman_state')) {
            ($this->recordInsert)($params[0]);
        }
        return parent::execute($params);
    }
}
