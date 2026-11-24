<?php

namespace Kanboard\Plugin\Foreman\Test;

use Kanboard\Core\Http\Request;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Foreman\Model\Health;
use Kanboard\Plugin\Foreman\Plugin;
use KanboardTests\units\Base;
use function Kanboard\Plugin\Foreman\Schema\version_1;

require_once 'tests/units/Base.php';

class HealthTest extends Base
{
    private const NOW = 1795395600;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());
        $this->container['hook'] = new Hook();
        $this->container['hook']->on('foreman:jobs', static function (): array {
            $defaults = ['plugin' => 'Example', 'run' => static fn (): array => ['status' => 'done']];

            return [
                ['id' => 'example.fast', 'interval' => 120] + $defaults,
                ['id' => 'example.slow', 'interval' => 600] + $defaults,
                ['id' => 'example.disabled', 'interval' => 60] + $defaults,
            ];
        });
        $this->container['route']->enable();
        $this->container['request'] = new Request($this->container, [
            'PHP_SELF' => '/index.php',
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ]);
        (new Plugin($this->container))->initialize();
        $this->container['foremanHealth'] = fn (): Health => $this->health();
        $_SESSION['user'] = ['id' => 1, 'role' => Role::APP_ADMIN];
    }

    public function testCountsOnlyEnabledFailuresAndLinksToJobs(): void
    {
        $this->state('example.fast', 'failed', self::NOW - 30);
        $this->state('example.slow', 'failed', self::NOW - 60);
        $this->state('example.disabled', 'failed', self::NOW - 90, enabled: 0);

        $this->assertSame(['failing' => 2, 'stale_minutes' => null], $this->health()->summary());
        $this->assertMatchesRegularExpression(
            '~<div\b[^>]*data-slot="callout"[^>]*>\s*<a href="/foreman/jobs"[^>]*>Foreman: 2 job\(s\) failing</a>~',
            $this->render()
        );
    }

    public function testOrphanedFailureDoesNotRenderAnAlert(): void
    {
        $this->state('removed.job', 'failed', self::NOW - 600);

        $this->assertSame(['failing' => 0, 'stale_minutes' => null], $this->health()->summary());
        $this->assertSame('', $this->render());
    }

    public function testStaleUsesNewestEnabledFinishAndShortestEnabledInterval(): void
    {
        $this->state('example.fast', 'done', self::NOW - 600);
        $this->state('example.slow', 'done', self::NOW - 300);
        $this->state('example.disabled', 'done', self::NOW, enabled: 0);

        $this->assertSame(['failing' => 0, 'stale_minutes' => 5], $this->health()->summary());
        $this->assertMatchesRegularExpression(
            '~<div\b[^>]*data-slot="callout"[^>]*>\s*<a href="/foreman/jobs"[^>]*>Foreman: no job has run for 5 minutes</a>~',
            $this->render()
        );
    }

    public function testStaleStartsOneSecondAfterTwiceTheShortestEnabledInterval(): void
    {
        $this->state('example.fast', 'done', self::NOW - 240);
        $this->state('example.slow', 'done', self::NOW - 300);
        $now = self::NOW;
        $health = new Health($this->container, static function () use (&$now): int {
            return $now;
        });

        $this->assertNull($health->summary()['stale_minutes']);
        ++$now;
        $this->assertSame(4, $health->summary()['stale_minutes']);
    }

    public function testNeverFinishedUsesOldestEnabledRow(): void
    {
        $this->state('example.fast', due: self::NOW - 60);
        $this->state('example.slow', due: self::NOW - 360);
        $this->state('example.disabled', due: self::NOW - 1200, enabled: 0);

        $this->assertSame(['failing' => 0, 'stale_minutes' => 6], $this->health()->summary());
    }

    public function testHealthyAndDisabledOnlyStatesRenderNothing(): void
    {
        $this->state('example.disabled', 'failed', self::NOW - 1200, enabled: 0);
        $this->assertSame(['failing' => 0, 'stale_minutes' => null], $this->health()->summary());
        $this->assertSame('', $this->render());

        $this->state('example.fast', 'done', self::NOW - 240);
        $this->assertSame(['failing' => 0, 'stale_minutes' => null], $this->health()->summary());
        $this->assertSame('', $this->render());
    }

    public function testNonAdminCannotSeeFailuresOrResolveHealth(): void
    {
        $this->state('example.fast', 'failed', self::NOW);
        $_SESSION['user'] = ['id' => 2, 'role' => Role::APP_USER];
        $resolved = false;
        $this->container['foremanHealth'] = function () use (&$resolved): Health {
            $resolved = true;

            return $this->health();
        };

        $this->assertSame('', $this->render());
        $this->assertFalse($resolved);
    }

    private function health(): Health
    {
        return new Health($this->container, static fn (): int => self::NOW);
    }

    private function state(string $id, ?string $status = null, ?int $finished = null, int $enabled = 1, int $due = self::NOW): void
    {
        $statement = $this->container['db']->getConnection()->prepare(
            'INSERT INTO foreman_state (job_id, plugin, enabled, next_due_at, last_finished_at, last_status) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$id, 'Example', $enabled, $due, $finished, $status]);
    }

    private function render(): string
    {
        return $this->container['helper']->hook->render('template:layout:top');
    }
}
