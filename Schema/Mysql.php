<?php

namespace Kanboard\Plugin\Foreman\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE foreman_state (
            job_id VARCHAR(64) NOT NULL PRIMARY KEY,
            plugin VARCHAR(64) NOT NULL,
            enabled INT NOT NULL DEFAULT 1,
            next_due_at INT NOT NULL,
            lease_until INT NULL,
            last_started_at INT NULL,
            last_finished_at INT NULL,
            last_status VARCHAR(10) NULL,
            consecutive_failures INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE foreman_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(64) NOT NULL,
            `trigger` VARCHAR(10) NOT NULL,
            started_at INT NOT NULL,
            finished_at INT NULL,
            status VARCHAR(10) NULL,
            summary VARCHAR(200) NULL,
            error_class VARCHAR(191) NULL,
            INDEX foreman_runs_job_started (job_id, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}
