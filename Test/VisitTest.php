<?php

namespace Kanboard\Plugin\Foreman\Test;

use Closure;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Plugin\Foreman\Model\Registry;
use Kanboard\Plugin\Foreman\Model\Runner;
use Kanboard\Plugin\Foreman\Plugin;
use Kanboard\Plugin\Foreman\Trigger\Visit;
use KanboardTests\units\Base;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use function Kanboard\Plugin\Foreman\Schema\version_1;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class VisitTest extends Base
{
    use ReleasesConnection {
        tearDown as releaseConnection;
    }

    private int $now = 1795395600;
    private ?Closure $deferred = null;
    private array $calls = [];
    private bool $originalIgnoreUserAbort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIgnoreUserAbort = (bool) ignore_user_abort();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());

        $this->container['hook'] = new Hook();
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['foreman_visit_mode' => 'always']);
        $this->container['foremanRegistry'] = new Registry($this->container, fn (): int => $this->now, fn (): bool => false);
    }

    protected function tearDown(): void
    {
        ignore_user_abort($this->originalIgnoreUserAbort);
        $this->releaseConnection();
    }

    public function testDisallowedModeDoesNotDefer(): void
    {
        $this->container['configModel']->save(['foreman_visit_mode' => 'never']);
        $this->expectNoTick($this->never());

        $this->assertFalse($this->visit(static fn (): bool => true)->arm());
        $this->assertNull($this->deferred);
    }

    public function testNoDueJobDoesNotDefer(): void
    {
        $this->expectNoTick($this->once());

        $this->assertFalse($this->visit(static fn (): bool => true)->arm());
        $this->assertNull($this->deferred);
    }

    public function testUnavailableFinishDoesNotDefer(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            $this->markTestSkipped('Requires a SAPI without fastcgi_finish_request');
        }
        $this->expectNoTick($this->never());

        $this->assertFalse($this->visit(null)->arm());
        $this->assertNull($this->deferred);
    }

    public function testFinishesResponseBeforeTickingWithFreshWebDeadline(): void
    {
        $runner = $this->dueRunner();
        $runner->expects($this->once())->method('tick')
            ->willReturnCallback(function (string $trigger, int $deadline): array {
                $this->calls[] = ['tick', $trigger, $deadline];

                return [];
            });
        $deadline = $this->now + 35;
        $visit = $this->visit(function (): void {
            $this->calls[] = 'finish';
            $this->now += 25;
        });

        $this->assertTrue($visit->arm());
        $this->assertSame([], $this->calls);
        ($this->deferred)();
        $this->assertSame(['finish', ['tick', 'web', $deadline]], $this->calls);
    }

    public function testFailedFinishDoesNotTick(): void
    {
        $this->dueRunner()->expects($this->never())->method('tick');

        $this->assertTrue($this->visit(fn (): bool => false)->arm());
        ($this->deferred)();
    }

    public function testDeferredRunnerErrorIsLoggedByClassOnly(): void
    {
        $this->dueRunner()->expects($this->once())->method('tick')
            ->willThrowException(new RuntimeException('private job details'));
        $this->expectVisitError('Foreman: deferred visit failed (RuntimeException)');

        $this->assertTrue($this->visit(static fn (): bool => true)->arm());
        ($this->deferred)();
    }

    public function testLayoutHookBootstrapsJobsOnFirstVisit(): void
    {
        $clock = fn (): int => $this->now;
        $this->container['foremanRunner'] = new Runner($this->container, $clock);
        $this->container['hook']->on('foreman:jobs', fn (): array => [[
            'id' => 'test:job',
            'plugin' => 'Test',
            'interval' => 60,
            'run' => function (int $deadline, string $trigger): array {
                $this->calls[] = ['job', $trigger];

                return ['status' => 'done'];
            },
        ]]);
        $this->container['foremanVisit'] = $this->visit(function (): void {
            $this->calls[] = 'finish';
        });

        $this->assertSame('', $this->container['helper']->hook->render('template:layout:top'));
        $this->assertInstanceOf(Closure::class, $this->deferred);
        $this->assertSame([], $this->calls);
        ($this->deferred)();
        $this->assertSame(['finish', ['job', 'web']], $this->calls);
    }

    public function testLayoutHookLogsArmErrorAndReturnsNoVariables(): void
    {
        $this->expectVisitError('Foreman: could not arm visit (RuntimeException)');
        $this->container['foremanVisit'] = function (): never {
            throw new RuntimeException('private setup details');
        };
        $listeners = $this->container['hook']->getListeners('template:layout:top');
        $this->assertCount(1, $listeners);
        [$listener] = $listeners;

        $this->assertSame('Foreman:layout/top', $listener['template']);
        $this->assertSame([], ($listener['callable'])());
    }

    private function visit(?callable $finish): Visit
    {
        return new Visit(
            $this->container,
            function (Closure $callback): void {
                $this->deferred = $callback;
            },
            $finish,
            fn (): int => $this->now
        );
    }

    private function expectNoTick(InvocationOrder $dueChecks): void
    {
        $runner = $this->createMock(Runner::class);
        $runner->expects($dueChecks)->method('hasDueJob')->willReturn(false);
        $runner->expects($this->never())->method('tick');
        $this->container['foremanRunner'] = $runner;
    }

    private function dueRunner(): Runner
    {
        $runner = $this->createMock(Runner::class);
        $runner->expects($this->once())->method('hasDueJob')->willReturn(true);
        $this->container['foremanRunner'] = $runner;

        return $runner;
    }

    private function expectVisitError(string $message): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($message);
        $this->container['logger'] = $logger;
    }
}
