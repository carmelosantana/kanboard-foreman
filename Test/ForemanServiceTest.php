<?php

namespace Kanboard\Plugin\Foreman\Test;

use Kanboard\Plugin\Foreman\Model\Foreman;
use Kanboard\Plugin\Foreman\Model\Lease;
use Kanboard\Plugin\Foreman\Model\LeaseHeld;
use Kanboard\Plugin\Foreman\Plugin;
use KanboardTests\units\Base;
use PDO;
use Pimple\Container;
use RuntimeException;
use function Kanboard\Plugin\Foreman\Schema\version_1;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class ForemanServiceTest extends Base
{
    use ReleasesConnection;

    private const NOW = 1700000000;
    private const JOB = 'sample:refresh';
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $this->pdo = $this->container['db']->getConnection();
        version_1($this->pdo);
    }

    public function testPluginRegistersForemanLazily(): void
    {
        $container = new Container();
        (new Plugin($container))->initialize();

        $this->assertTrue(isset($container['foreman']));
        $container['db'] = $this->container['db'];
        $this->assertInstanceOf(Foreman::class, $container['foreman']);
    }

    public function testWithLeaseCreatesMissingStateAndReturnsCallbackResult(): void
    {
        $foreman = new Foreman($this->container, fn (): int => self::NOW);
        $result = $foreman->withLease(self::JOB, function (): string {
            $state = $this->pdo->query('SELECT * FROM foreman_state')->fetch(PDO::FETCH_ASSOC);

            $this->assertSame(self::JOB, $state['job_id']);
            $this->assertSame('unknown', $state['plugin']);
            $this->assertSame(self::NOW, (int) $state['next_due_at']);
            $this->assertSame(self::NOW + 3600, (int) $state['lease_until']);

            return 'finished';
        });

        $this->assertSame('finished', $result);
        $this->assertNull($this->pdo->query('SELECT lease_until FROM foreman_state')->fetchColumn());
    }

    public function testWithLeaseReleasesOnException(): void
    {
        $foreman = new Foreman($this->container, fn (): int => self::NOW);
        $failure = new RuntimeException('Callback failed');
        $this->expectExceptionObject($failure);

        try {
            $foreman->withLease(self::JOB, static function () use ($failure): never {
                throw $failure;
            }, 10);
        } finally {
            $lease = new Lease($this->pdo, fn (): int => self::NOW);
            $this->assertTrue($lease->claim(self::JOB, self::NOW + 10));
        }
    }

    public function testWithLeaseThrowsLeaseHeld(): void
    {
        $this->pdo->prepare('INSERT INTO foreman_state (job_id, plugin, next_due_at, lease_until) VALUES (?, ?, ?, ?)')
            ->execute([self::JOB, 'Sample', self::NOW + 60, self::NOW + 10]);
        $foreman = new Foreman($this->container, fn (): int => self::NOW);
        $this->expectException(LeaseHeld::class);

        try {
            $foreman->withLease(self::JOB, fn () => $this->fail('A held lease must prevent the callback.'));
        } finally {
            $state = $this->pdo->query('SELECT * FROM foreman_state')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('Sample', $state['plugin']);
            $this->assertSame(self::NOW + 60, (int) $state['next_due_at']);
            $this->assertSame(self::NOW + 10, (int) $state['lease_until']);
        }
    }
}
