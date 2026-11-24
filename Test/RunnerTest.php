<?php

namespace Kanboard\Plugin\Foreman\Test;

use DateTimeImmutable;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Plugin\Foreman\Model\Lease;
use Kanboard\Plugin\Foreman\Model\Registry;
use Kanboard\Plugin\Foreman\Model\RunLog;
use Kanboard\Plugin\Foreman\Model\Runner;
use Kanboard\Plugin\Foreman\Model\Schedule;
use Kanboard\Plugin\Foreman\Plugin;
use KanboardTests\units\Base;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\GenericEvent;
use UnexpectedValueException;
use function Kanboard\Plugin\Foreman\Schema\version_1;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class RunnerTest extends Base
{
    use ReleasesConnection;

    private const NOW = 1795395600; // 2026-11-23 01:00 UTC
    private int $now;
    private PDO $pdo;
    private array $jobs;
    private array $calls;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $this->pdo = $this->container['db']->getConnection();
        version_1($this->pdo);

        $this->now = self::NOW;
        $this->calls = [];
        $this->jobs = array_map(fn ($id) => $this->job($id), ['c', 'b', 'a']);
        $this->container['hook'] = new Hook();
        $this->container['hook']->on('foreman:jobs', fn (): array => $this->jobs);
        $this->container['foremanRegistry'] = new Registry($this->container, $this->clock(), static fn () => false);
    }

    public function testPluginRegistersRunner(): void
    {
        (new Plugin($this->container))->initialize();

        $this->assertInstanceOf(Runner::class, $this->container['foremanRunner']);
    }

    public function testRunsDueJobsInOrderAndRecordsEachRun(): void
    {
        $runner = $this->runner();
        $this->assertFalse($runner->hasDueJob());
        $this->sync();
        $this->setDue('c', self::NOW - 1);

        $this->assertTrue($runner->hasDueJob());
        $this->assertSame([
            ['job_id' => 'c', 'status' => 'done', 'summary' => 'ran c'],
            ['job_id' => 'a', 'status' => 'done', 'summary' => 'ran a'],
            ['job_id' => 'b', 'status' => 'done', 'summary' => 'ran b'],
        ], $runner->tick('cli', self::NOW + 50));
        $this->assertSame(['c', 'a', 'b'], array_column($this->calls, 0));
        $this->assertFalse($runner->hasDueJob());

        foreach (['c', 'a', 'b'] as $id) {
            $runs = $this->log()->recent($id);
            $this->assertCount(1, $runs);
            $run = $runs[0];
            $this->assertSame($id, $run['job_id']);
            $this->assertSame('cli', $run['trigger']);
            $this->assertSame(self::NOW, (int) $run['started_at']);
            $this->assertSame(self::NOW, (int) $run['finished_at']);
            $this->assertSame('done', $run['status']);
            $this->assertSame('ran '.$id, $run['summary']);
            $this->assertNull($run['error_class']);
            $state = $this->state($id);
            $this->assertSame(self::NOW, (int) $state['last_started_at']);
            $this->assertSame(self::NOW, (int) $state['last_finished_at']);
            $this->assertSame(self::NOW + 120, (int) $state['next_due_at']);
            $this->assertSame('done', $state['last_status']);
            $this->assertNull($state['lease_until']);
        }
    }

    public function testSkipsDisabledFutureHeldAndUnregisteredJobs(): void
    {
        $this->sync();
        $this->pdo->exec("UPDATE foreman_state SET enabled = 0 WHERE job_id = 'a'");
        $this->setDue('b', self::NOW + 60);
        $this->pdo->exec("INSERT INTO foreman_state (job_id, plugin, next_due_at) VALUES ('old', 'Example', 0)");
        $lease = new Lease($this->pdo, $this->clock());
        $this->assertTrue($lease->claim('c', self::NOW + 200));

        $runner = $this->runner();
        $this->assertSame([], $runner->tick('cli', self::NOW + 50));
        $this->assertSame([], $runner->tick('manual', self::NOW + 50, 'c'));
        $this->assertSame([], $runner->tick('manual', self::NOW + 50, 'a'));
        $this->assertSame([], $runner->tick('manual', self::NOW + 50, 'old'));
        $this->assertSame(self::NOW + 200, (int) $this->state('c')['lease_until']);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM foreman_runs')->fetchColumn());

        $lease->release('c');
        $this->setDue('c', self::NOW + 60);
        $this->assertFalse($runner->hasDueJob());
    }

    public function testOnlyJobRunsEarlyWithTheNonWebDeadline(): void
    {
        $this->sync();
        $this->setDue('b', self::NOW + 60);

        $this->assertSame(['b'], array_column($this->runner()->tick('manual', self::NOW + 50, 'b'), 'job_id'));
        $this->assertSame([['b', self::NOW + 50, 'manual']], $this->calls);
        $this->assertSame('manual', $this->log()->recent('b')[0]['trigger']);
    }

    public function testTickSyncsAndAppliesBothWebDeadlineCaps(): void
    {
        $this->jobs[0]['budget'] = 100;
        $this->jobs[1]['budget'] = 4;
        $this->runner()->tick('web', self::NOW + 25);

        $this->assertSame([
            ['a', self::NOW + 10, 'web'],
            ['b', self::NOW + 4, 'web'],
            ['c', self::NOW + 25, 'web'],
        ], $this->calls);
    }

    public function testStopsStartingJobsAtTheDeadline(): void
    {
        $deadline = self::NOW + 50;
        $this->jobs[2]['run'] = function () use ($deadline): array {
            $this->assertSame($deadline + 60, (int) $this->state('a')['lease_until']);
            $this->now = $deadline + 1;

            return ['status' => 'more'];
        };
        $runner = $this->runner();

        $this->assertSame([['job_id' => 'a', 'status' => 'more', 'summary' => '']], $runner->tick('cli', $deadline));
        $state = $this->state('a');
        $this->assertSame($this->now, (int) $state['next_due_at']);
        $this->assertSame($this->now, (int) $state['last_finished_at']);
        $this->assertSame($this->now, (int) $this->log()->recent('a')[0]['finished_at']);
        $this->assertNull($state['lease_until']);
        $this->assertSame([], $this->calls);
        $this->assertSame([], $runner->tick('cli', $this->now));
    }

    public function testSkipsCandidateRescheduledByAnotherTick(): void
    {
        $this->jobs[2]['run'] = function (): array {
            $this->assertSame(['b'], array_column($this->runner()->tick('cli', self::NOW + 50, 'b'), 'job_id'));

            return ['status' => 'done'];
        };

        $this->assertSame(['a', 'c'], array_column($this->runner()->tick('cli', self::NOW + 50), 'job_id'));
        $this->assertCount(1, $this->log()->recent('b'));
    }

    public function testFailuresAndRecoveryDispatchSafePayloads(): void
    {
        $events = [];
        foreach (['foreman.job.failed', 'foreman.job.recovered'] as $event) {
            $this->dispatcher->addListener($event, static function (GenericEvent $received) use (&$events, $event): void {
                $events[] = [$event, $received->getSubject()];
            });
        }
        $status = 'failed';
        $this->jobs[2]['run'] = static function () use (&$status): array {
            if ($status === 'failed') {
                throw new RuntimeException('secret');
            }

            return ['status' => $status];
        };
        $runner = $this->runner();
        $this->sync();
        $this->assertSame(0, (int) $this->state('a')['consecutive_failures']);

        $expected = [];
        foreach (['failed', 'failed', 'more', 'done', 'done'] as $index => $status) {
            $result = $runner->tick('worker', $this->now + 50, 'a');
            $this->assertSame([['job_id' => 'a', 'status' => $status, 'summary' => '']], $result);
            $state = $this->state('a');
            $count = [1, 2, 2, 0, 0][$index];
            $this->assertSame($count, (int) $state['consecutive_failures']);
            $this->assertNull($state['lease_until']);
            $this->assertSame($status, $state['last_status']);
            $this->assertSame($this->now + ($status === 'more' ? 0 : 120), (int) $state['next_due_at']);

            $run = $this->log()->recent('a', 1)[0];
            $errorClass = $status === 'failed' ? RuntimeException::class : null;
            $this->assertSame($errorClass, $run['error_class']);
            if ($status === 'failed' || $index === 3) {
                $expected[] = [$status === 'failed' ? 'foreman.job.failed' : 'foreman.job.recovered', [
                    'job_id' => 'a',
                    'plugin' => 'Example',
                    'run_id' => (int) $run['id'],
                    'consecutive_failures' => $count,
                    'error_class' => $errorClass,
                ]];
            }
            $this->assertSame($expected, $events);
        }

        foreach (['foreman_runs', 'foreman_state'] as $table) {
            $this->assertStringNotContainsString('secret', json_encode($this->pdo->query('SELECT * FROM '.$table)->fetchAll()));
        }

        $runs = $this->log()->recent('a', 2);
        $this->assertSame(['done', 'done'], array_column($runs, 'status'));
        $this->assertGreaterThan((int) $runs[1]['id'], (int) $runs[0]['id']);
    }

    /** @dataProvider throwingListeners */
    public function testThrowingListenerLeavesRunFinishedAndTickContinues(string $event, string $status): void
    {
        $this->jobs[2]['run'] = static fn () => ['status' => $status];
        $this->sync();
        if ($status === 'done') {
            $this->pdo->exec("UPDATE foreman_state SET consecutive_failures = 1 WHERE job_id = 'a'");
        }

        $logger = $this->createMock(LoggerInterface::class);
        $warnings = [];
        $logger->method('warning')->willReturnCallback(
            static function ($message, array $context = []) use (&$warnings): void {
                $warnings[] = [$message, $context];
            }
        );
        $this->container['logger'] = $logger;
        $this->dispatcher->addListener($event, static function (): void {
            throw new RuntimeException('listener secret');
        });

        $results = $this->runner()->tick('cli', self::NOW + 50);
        $this->assertSame(['a', 'b', 'c'], array_column($results, 'job_id'));
        $this->assertSame([$status, 'done', 'done'], array_column($results, 'status'));
        $run = $this->log()->recent('a')[0];
        $this->assertSame($status, $run['status']);
        $this->assertSame(self::NOW, (int) $run['finished_at']);
        $this->assertNull($run['error_class']);
        $this->assertSame(['Foreman: event listener failed (RuntimeException)', []], end($warnings));
        $this->assertStringNotContainsString('listener secret', json_encode($warnings));
    }

    public static function throwingListeners(): array
    {
        return [
            'failure event' => ['foreman.job.failed', 'failed'],
            'recovery event' => ['foreman.job.recovered', 'done'],
        ];
    }

    /** @dataProvider malformedResults */
    public function testMalformedResultsFailWithoutStoppingTheTick(mixed $result): void
    {
        $this->jobs[2]['run'] = static fn () => $result;

        $this->assertSame(['failed', 'done', 'done'], array_column($this->runner()->tick('cli', self::NOW + 50), 'status'));
        $this->assertSame(UnexpectedValueException::class, $this->log()->recent('a')[0]['error_class']);
        $this->assertNull($this->state('a')['lease_until']);
    }

    public static function malformedResults(): array
    {
        return [
            'scalar' => ['oops'],
            'unknown status' => [['status' => 'maybe']],
        ];
    }

    public function testExplicitFailureTruncatesSummaryByCharacters(): void
    {
        $this->jobs[2]['run'] = static fn () => ['status' => 'failed', 'summary' => str_repeat('é', 300)];

        $result = $this->runner()->tick('cli', self::NOW + 50, 'a');
        $this->assertSame(str_repeat('é', 200), $result[0]['summary']);
        $run = $this->log()->recent('a')[0];
        $this->assertSame(str_repeat('é', 200), $run['summary']);
        $this->assertNull($run['error_class']);
        $this->assertNull($this->state('a')['lease_until']);
    }

    /** @dataProvider fallbackTimezones */
    public function testEmptyAndInvalidTimezoneUseUtc(string $zone): void
    {
        $this->container['configModel']->save(['application_timezone' => $zone]);
        $this->assertSame('UTC', Schedule::tz($this->container)->getName());

        $this->runner()->tick('manual', self::NOW + 50);
        $this->assertSame(self::NOW + 7200, (int) $this->state('foreman.prune')['next_due_at']);
    }

    public static function fallbackTimezones(): array
    {
        return ['empty' => [''], 'invalid' => ['Not/A_Timezone']];
    }

    public function testRunnerSchedulesDailyJobInApplicationTimezone(): void
    {
        $this->container['configModel']->save(['application_timezone' => 'America/New_York']);
        $this->jobs[2]['hour'] = 8;
        $this->runner()->tick('manual', self::NOW + 50, 'a');

        $expected = new DateTimeImmutable('2026-11-23 08:00:00 America/New_York');
        $this->assertSame($expected->getTimestamp(), (int) $this->state('a')['next_due_at']);
    }

    private function job(string $id): array
    {
        return [
            'id' => $id,
            'plugin' => 'Example',
            'title' => $id,
            'interval' => 120,
            'run' => function (int $deadline, string $trigger) use ($id): array {
                $this->calls[] = [$id, $deadline, $trigger];

                return ['status' => 'done', 'summary' => 'ran '.$id];
            },
        ];
    }

    private function clock(): callable
    {
        return fn (): int => $this->now;
    }

    private function runner(): Runner
    {
        return new Runner($this->container, $this->clock());
    }

    private function log(): RunLog
    {
        return new RunLog($this->container, $this->clock());
    }

    private function sync(): void
    {
        $this->container['foremanRegistry']->sync();
    }

    private function setDue(string $jobId, int $due): void
    {
        $this->pdo->prepare('UPDATE foreman_state SET next_due_at = ? WHERE job_id = ?')->execute([$due, $jobId]);
    }

    private function state(string $jobId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM foreman_state WHERE job_id = ?');
        $statement->execute([$jobId]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }
}
