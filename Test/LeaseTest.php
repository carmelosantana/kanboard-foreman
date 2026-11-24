<?php

namespace Kanboard\Plugin\Foreman\Test;

use Kanboard\Plugin\Foreman\Model\Lease;
use KanboardTests\units\Base;
use PDO;
use function Kanboard\Plugin\Foreman\Schema\version_1;

require_once 'tests/units/Base.php';

class LeaseTest extends Base
{
    private const JOB = 'sample:refresh';
    private int $now = 1700000000;

    public function testClaimSucceedsOnce(): void
    {
        $lease = $this->createLease();

        $this->assertTrue($lease->claim(self::JOB, $this->now + 10));
        $this->assertFalse($lease->claim(self::JOB, $this->now + 10));
    }

    public function testExpiredLeaseIsClaimable(): void
    {
        $lease = $this->createLease();

        $this->assertTrue($lease->claim(self::JOB, $this->now + 10));
        $this->now += 10;
        $this->assertFalse($lease->claim(self::JOB, $this->now + 10));
        $this->now++;
        $this->assertTrue($lease->claim(self::JOB, $this->now + 10));
    }

    public function testReleaseFreesTheLease(): void
    {
        $lease = $this->createLease();

        $this->assertTrue($lease->claim(self::JOB, $this->now + 10));
        $lease->release(self::JOB);
        $this->assertTrue($lease->claim(self::JOB, $this->now + 10));
    }

    public function testExpiredOwnerCannotReleaseNewerLease(): void
    {
        $first = $this->createLease();
        $pdo = $this->container['db']->getConnection();
        $second = new Lease($pdo, fn (): int => $this->now);
        $third = new Lease($pdo, fn (): int => $this->now);

        $this->assertTrue($first->claim(self::JOB, $this->now + 10));
        $this->now += 11;
        $this->assertTrue($second->claim(self::JOB, $this->now + 60));

        $first->release(self::JOB);

        $this->assertFalse($third->claim(self::JOB, $this->now + 60));
    }

    public function testReleaseWithoutSuccessfulClaimDoesNothing(): void
    {
        $owner = $this->createLease();
        $pdo = $this->container['db']->getConnection();
        $other = new Lease($pdo, fn (): int => $this->now);

        $this->assertTrue($owner->claim(self::JOB, $this->now + 10));
        $this->assertFalse($other->claim(self::JOB, $this->now + 10));
        $other->release(self::JOB);

        $this->assertFalse($other->claim(self::JOB, $this->now + 10));
    }

    public function testClaimUnknownJobIsFalse(): void
    {
        $lease = $this->createLease();

        $this->assertFalse($lease->claim('missing', $this->now + 10));
    }

    public function testTwoProcessesOneWinner(): void
    {
        if (DB_DRIVER !== 'sqlite') {
            $this->markTestSkipped('The two-process lease test requires SQLite.');
        }

        $dbFile = tempnam(sys_get_temp_dir(), 'foreman-lease-');
        $this->assertNotFalse($dbFile);
        $workers = [];
        try {
            $pdo = new PDO('sqlite:'.$dbFile);
            $this->seedJob($pdo);
            $pdo = null;

            for ($i = 0; $i < 2; $i++) {
                $process = proc_open(
                    [PHP_BINARY, __DIR__.'/fixtures/lease-race.php', $dbFile],
                    [1 => ['pipe', 'w']],
                    $pipes
                );
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes[1]];
            }

            $results = [];
            foreach ($workers as $i => [$process, $output]) {
                $results[] = trim(stream_get_contents($output));
                fclose($output);
                $exitCode = proc_close($process);
                unset($workers[$i]);
                $this->assertSame(0, $exitCode);
            }

            sort($results);
            $this->assertSame(['0', '1'], $results);
        } finally {
            foreach ($workers as [$process, $output]) {
                fclose($output);
                proc_terminate($process);
                proc_close($process);
            }
            unlink($dbFile);
        }
    }

    private function createLease(): Lease
    {
        $pdo = $this->container['db']->getConnection();
        $this->seedJob($pdo);

        return new Lease($pdo, fn (): int => $this->now);
    }

    private function seedJob(PDO $pdo): void
    {
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($pdo);
        $pdo->prepare('INSERT INTO foreman_state (job_id, plugin, next_due_at) VALUES (?, ?, ?)')
            ->execute([self::JOB, 'Sample', $this->now]);
    }
}
