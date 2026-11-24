<?php

use Kanboard\Plugin\Foreman\Model\Lease;

require_once __DIR__.'/../../Model/Lease.php';

$pdo = new PDO('sqlite:'.$argv[1], options: [PDO::ATTR_TIMEOUT => 5]);
// Give the other process time to read the old lease while this write is pending.
$pdo->sqliteCreateFunction('delay_write', static fn () => usleep(250000), 0);
$pdo->exec('CREATE TEMP TRIGGER delay_lease BEFORE UPDATE ON foreman_state BEGIN SELECT delay_write(); END');

$now = 1700000000;
$lease = new Lease($pdo, static fn (): int => $now);
echo $lease->claim('sample:refresh', $now + 10) ? '1' : '0';
