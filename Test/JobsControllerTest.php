<?php

namespace Kanboard\Plugin\Foreman\Test;

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Foreman\Controller\JobsController;
use Kanboard\Plugin\Foreman\Model\Registry;
use Kanboard\Plugin\Foreman\Model\RunLog;
use Kanboard\Plugin\Foreman\Model\Runner;
use Kanboard\Plugin\Foreman\Model\Settings;
use Kanboard\Plugin\Foreman\Plugin;
use KanboardTests\units\Base;
use RuntimeException;
use function Kanboard\Plugin\Foreman\Schema\version_1;

require_once 'tests/units/Base.php';

class JobsControllerTest extends Base
{
    private const JOB = 'example.refresh';
    private int $now = 1795395600;
    private string $html = '';
    private array $host = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());
        $this->container['hook'] = new Hook();
        $this->container['hook']->on('foreman:jobs', fn (): array => [[
            'id' => self::JOB,
            'plugin' => 'Example',
            'title' => 'Refresh inventory',
            'interval' => 120,
            'link' => ['controller' => 'InventoryController', 'action' => 'show', 'params' => ['scope' => 'active']],
            'run' => static fn (): array => ['status' => 'done'],
        ], [
            'id' => 'example.fast',
            'plugin' => 'Example',
            'title' => 'Fast job',
            'interval' => 60,
            'run' => static fn (): array => ['status' => 'done'],
        ]]);
        $this->container['hook']->on('foreman:host', fn (): array => $this->host);
        $this->container['route']->enable();
        $this->request();
        (new Plugin($this->container))->initialize();
        $clock = fn (): int => $this->now;
        $settings = new Settings($this->container, $clock);
        $this->container['foremanSettings'] = $settings;
        $this->container['foremanRegistry'] = new Registry($this->container, $clock, $settings->coreCronjobEnabled(...));

        $response = $this->getMockBuilder(Response::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['html', 'send'])
            ->getMock();
        $response->method('html')->willReturnCallback(function (string $html): void {
            $this->html = $html;
        });
        $this->container['response'] = $response;
    }

    public function testRoutesAndAdminAccess(): void
    {
        foreach (['index' => '', 'runs' => '/runs', 'run' => '/run', 'toggle' => '/toggle', 'save' => '/save'] as $action => $suffix) {
            $route = $this->container['route']->findRoute('foreman/jobs'.$suffix);
            $this->assertSame(['controller' => 'JobsController', 'action' => $action, 'plugin' => 'Foreman'], $route);
            $auth = $this->container['applicationAuthorization'];
            $this->assertTrue($auth->isAllowed('JobsController', $action, Role::APP_ADMIN));
            $this->assertFalse($auth->isAllowed('JobsController', $action, Role::APP_MANAGER));
        }
    }

    public function testAssetsAreScopedToTheJobsRoutes(): void
    {
        $this->assertFalse($this->container['hook']->exists('template:layout:css'));
        $this->assertTrue($this->container['hook']->exists('template:config:sidebar'));

        foreach ([
            ['/foreman/jobs/runs', ['job_id' => self::JOB]],
            ['/index.php', ['controller' => 'JobsController', 'action' => 'index', 'plugin' => 'Foreman']],
        ] as [$path, $query]) {
            $this->container['hook'] = new Hook();
            $this->request(get: $query, path: $path);
            (new Plugin($this->container))->initialize();
            $this->assertSame([['template' => 'plugins/Foreman/Assets/css/foreman.css']],
                $this->container['hook']->getListeners('template:layout:css'));
        }
    }

    public function testIndexShowsJobDetailsAndHeartbeat(): void
    {
        $this->container['foremanRegistry']->sync();
        $this->container['foremanSettings']->setJobEnabled('example.fast', false);
        $statement = $this->container['db']->getConnection()->prepare(
            "UPDATE foreman_state SET last_status = 'more', last_finished_at = ?, next_due_at = ? WHERE job_id = ?"
        );
        $statement->execute([$this->now - 180, $this->now + 120, self::JOB]);

        $this->controller()->index();
        foreach ([
            'core.cronjob', 'foreman.prune', 'example.fast', self::JOB, 'Example', 'Refresh inventory',
            'Every 2 minutes', '>more<', $this->container['helper']->dt->datetime($this->now + 120),
            'controller=InventoryController', 'action=show', 'plugin=Example', 'scope=active',
            'Page visits only', '* * * * * cd /var/www/app &amp;&amp; php cli foreman:run',
        ] as $expected) {
            $this->assertStringContainsString($expected, $this->html);
        }
        $this->assertStringNotContainsString('Foreman: no job has run', $this->html);

        $this->container['foremanSettings']->markCliSeen();
        $this->now += 120;
        $this->controller()->index();
        $this->assertStringContainsString('Cron last seen 2 min ago', $this->html);
        $this->assertStringContainsString('Foreman: no job has run for 5 minutes', $this->html);
        $this->assertStringContainsString('* * * * * cd /var/www/app &amp;&amp; php cli foreman:run', $this->html);
    }

    public function testIndexShowsForcedVisitSettingAndHostSchedules(): void
    {
        $this->host = [
            'name' => 'Floorboard',
            'visit_fallback' => 'never',
            'schedules' => static fn (): array => [
                ['name' => 'Floorboard timer', 'status' => 'active'],
                ['name' => 'System snapshot', 'status' => '<waiting>'],
            ],
        ];
        $this->controller()->index();
        $this->assertMatchesRegularExpression('/<select\b(?=[^>]*name="foreman_visit_mode")(?=[^>]*disabled)[^>]*>/', $this->html);
        $this->assertMatchesRegularExpression('/<option\b(?=[^>]*value="never")(?=[^>]*selected)[^>]*>/', $this->html);
        $this->assertStringContainsString('Floorboard timer', $this->html);
        $this->assertStringContainsString('System snapshot', $this->html);
        $this->assertStringContainsString('&lt;waiting&gt;', $this->html);
    }

    public function testRunDelegatesToTheHostWithoutTicking(): void
    {
        $ids = [];
        $this->host = ['run_job' => static function (string $id) use (&$ids): array {
            $ids[] = $id;

            return ['accepted' => true];
        }];
        $this->expectNoTick();
        $this->post(['job_id' => self::JOB]);
        $this->controller()->run();
        $this->assertSame([self::JOB], $ids);
    }

    public function testRunTicksInlineWithTenSecondDeadline(): void
    {
        $runner = $this->createMock(Runner::class);
        $runner->expects($this->once())->method('tick')->with('manual', $this->now + 10, self::JOB)
            ->willReturn([['job_id' => self::JOB, 'status' => 'done', 'summary' => '']]);
        $this->container['foremanRunner'] = $runner;
        $this->post(['job_id' => self::JOB]);
        $this->controller()->run();
    }

    public function testRunWithInvalidCsrfHasNoSideEffects(): void
    {
        $this->host = ['run_job' => static fn () => throw new RuntimeException('Unexpected adapter call')];
        $this->expectNoTick();
        $this->request(post: ['job_id' => self::JOB, 'csrf_token' => 'invalid']);
        $this->expectException(AccessForbiddenException::class);
        $this->controller()->run();
    }

    public function testTogglePersistsEnabledState(): void
    {
        foreach (['0', '1'] as $enabled) {
            $this->post(['job_id' => self::JOB, 'enabled' => $enabled]);
            $this->controller()->toggle();
            $this->assertEquals((int) $enabled, $this->enabled(self::JOB));
        }
    }

    public function testSaveRejectsInvalidVisitMode(): void
    {
        $this->container['configModel']->save(['foreman_visit_mode' => 'always']);
        $this->post(['foreman_visit_mode' => 'sometimes', 'foreman_core_cronjob' => '1']);
        $this->controller()->save();
        $this->assertNotEmpty($this->container['flash']->getMessage('failure'));
        $this->assertSame('always', $this->container['foremanSettings']->visitMode());
        $this->assertFalse($this->container['foremanSettings']->coreCronjobEnabled());
    }

    public function testSaveUpdatesExistingCoreJobAndAllowsOmittedForcedSetting(): void
    {
        $this->container['configModel']->save(['foreman_visit_mode' => 'always']);
        $this->container['foremanRegistry']->sync();
        $this->host = ['visit_fallback' => 'never'];
        foreach (['1', '0'] as $enabled) {
            $this->post(['foreman_core_cronjob' => $enabled]);
            $this->controller()->save();
            $this->assertEquals((int) $enabled, $this->enabled('core.cronjob'));
            $this->assertSame($enabled === '1', $this->container['foremanSettings']->coreCronjobEnabled());
        }
        $this->host = [];
        $this->assertSame('always', $this->container['foremanSettings']->visitMode());
    }

    public function testRunsShowsSafeHistoryAndDistinguishesInterruptedAndActiveRuns(): void
    {
        $this->container['foremanRegistry']->sync();
        $log = new RunLog($this->container, fn (): int => $this->now);
        $log->start(self::JOB, 'cli');
        $this->now += 120;
        $runner = new Runner($this->container, fn (): int => $this->now);
        // Record a thrown failure through the same run-log path used by the scheduler.
        $this->container['foremanRegistry'] = $registry = $this->createMock(Registry::class);
        $registry->method('jobs')->willReturn([self::JOB => [
            'id' => self::JOB, 'plugin' => 'Example', 'interval' => 120,
            'run' => static fn () => throw new RuntimeException('private exception message'),
        ]]);
        $runner->tick('manual', $this->now + 10, self::JOB);
        $other = $log->start('foreman.prune', 'manual');
        $log->finish($other, 'done', 'unrelated run summary', null);
        $this->now += 1;
        $log->start(self::JOB, 'web');
        $statement = $this->container['db']->getConnection()->prepare(
            'UPDATE foreman_state SET lease_until = ?, last_started_at = ? WHERE job_id = ?'
        );
        $statement->execute([$this->now + 70, $this->now, self::JOB]);

        $this->request(get: ['job_id' => self::JOB]);
        $this->controller()->runs();
        foreach (['RuntimeException', '>interrupted<', '>running<'] as $expected) {
            $this->assertStringContainsString($expected, $this->html);
        }
        $this->assertStringNotContainsString('private exception message', $this->html);
        $this->assertStringNotContainsString('unrelated run summary', $this->html);

        $this->now += 71;
        $this->controller()->runs();
        $this->assertSame(2, substr_count($this->html, '>interrupted<'));
        $this->assertStringNotContainsString('>running<', $this->html);
    }

    private function controller(): JobsController
    {
        return new JobsController($this->container, fn (): int => $this->now);
    }

    private function expectNoTick(): void
    {
        $runner = $this->createMock(Runner::class);
        $runner->expects($this->never())->method('tick');
        $this->container['foremanRunner'] = $runner;
    }

    private function enabled(string $id): int
    {
        $statement = $this->container['db']->getConnection()->prepare('SELECT enabled FROM foreman_state WHERE job_id = ?');
        $statement->execute([$id]);
        $value = $statement->fetchColumn();
        $this->assertNotFalse($value);

        return (int) $value;
    }

    private function post(array $values): void
    {
        $this->request(post: $values + ['csrf_token' => $this->container['token']->getCSRFToken()]);
    }

    private function request(array $get = [], array $post = [], string $path = '/index.php'): void
    {
        $this->container['request'] = new Request($this->container, [
            'PHP_SELF' => '/index.php',
            'REQUEST_URI' => $path,
            'REQUEST_METHOD' => $post === [] ? 'GET' : 'POST',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], $get, $post);
    }
}
