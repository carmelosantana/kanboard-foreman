<?php

namespace Kanboard\Plugin\Foreman\Test;

use KanboardTests\units\Base;
use PDO;
use PDOException;
use function Kanboard\Plugin\Foreman\Schema\version_1;
use const Kanboard\Plugin\Foreman\Schema\VERSION;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class SchemaTest extends Base
{
    use ReleasesConnection;

    public function testVersion1Tables(): void
    {
        $schemaPath = __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $this->assertFileExists($schemaPath);
        require_once $schemaPath;
        $this->assertSame(1, VERSION);

        $pdo = $this->container['db']->getConnection();
        version_1($pdo);

        $insertState = "INSERT INTO foreman_state (job_id, plugin, next_due_at)
            VALUES ('sample:refresh', 'Sample', 1700000000)";
        $pdo->exec($insertState);
        $state = $pdo->query("SELECT * FROM foreman_state WHERE job_id = 'sample:refresh'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Sample', $state['plugin']);
        $this->assertSame(1700000000, (int) $state['next_due_at']);
        $this->assertSame(1, (int) $state['enabled']);
        $this->assertSame(0, (int) $state['consecutive_failures']);
        $this->assertNull($state['lease_until']);
        $this->assertNull($state['last_started_at']);
        $this->assertNull($state['last_finished_at']);
        $this->assertNull($state['last_status']);

        $triggerColumn = DB_DRIVER === 'mysql' ? '`trigger`' : '"trigger"';
        $pdo->exec("INSERT INTO foreman_runs (job_id, $triggerColumn, started_at)
            VALUES ('sample:refresh', 'cli', 1700000001)");
        $run = $pdo->query("SELECT * FROM foreman_runs WHERE job_id = 'sample:refresh'")->fetch(PDO::FETCH_ASSOC);
        $this->assertGreaterThan(0, (int) $run['id']);
        $this->assertSame('cli', $run['trigger']);
        $this->assertSame(1700000001, (int) $run['started_at']);
        $this->assertNull($run['finished_at']);
        $this->assertNull($run['status']);
        $this->assertNull($run['summary']);
        $this->assertNull($run['error_class']);

        $this->expectException(PDOException::class);
        $pdo->exec($insertState);
    }
}
