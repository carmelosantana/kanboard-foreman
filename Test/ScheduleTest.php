<?php

namespace Kanboard\Plugin\Foreman\Test;

use DateTimeImmutable;
use DateTimeZone;
use Kanboard\Plugin\Foreman\Model\Schedule;
use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    /** @dataProvider nextDueCases */
    public function testNextDue(array $job, string $status, string $finished, int|string $expected): void
    {
        $tz = new DateTimeZone('America/New_York');
        $now = (new DateTimeImmutable($finished, $tz))->getTimestamp();
        $due = Schedule::nextDue($job, $status, $now, $tz);

        if (is_int($expected)) {
            $this->assertSame($now + $expected, $due);
        } else {
            $localDue = (new DateTimeImmutable('@'.$due))->setTimezone($tz);
            $this->assertSame($expected, $localDue->format('Y-m-d H:i'));
        }
    }

    public static function nextDueCases(): array
    {
        $daily = ['interval' => 86400, 'hour' => 8];
        $finished = '2026-11-23 09:00';

        return [
            'more' => [$daily, 'more', $finished, 0],
            'failed retry cap' => [['interval' => 3600], 'failed', $finished, 900],
            'failed short interval' => [['interval' => 300], 'failed', $finished, 300],
            'done without hour' => [['interval' => 300], 'done', $finished, 300],
            'done after scheduled hour' => [$daily, 'done', $finished, '2026-11-24 08:00'],
            'done at scheduled hour' => [$daily, 'done', '2026-11-23 08:00', '2026-11-24 08:00'],
            'done before scheduled hour' => [$daily, 'done', '2026-11-23 07:00', '2026-11-23 08:00'],
            'done before spring forward' => [$daily, 'done', '2026-03-07 09:00', '2026-03-08 08:00'],
            'done after spring forward' => [$daily, 'done', '2026-03-08 09:00', '2026-03-09 08:00'],
        ];
    }

    public function testFirstDueWaitsUntilTheScheduledHour(): void
    {
        $tz = new DateTimeZone('America/New_York');
        $early = (new DateTimeImmutable('2026-11-23 06:00', $tz))->getTimestamp();
        $target = (new DateTimeImmutable('2026-11-23 08:00', $tz))->getTimestamp();
        $late = (new DateTimeImmutable('2026-11-23 10:00', $tz))->getTimestamp();
        $job = ['interval' => 86400, 'hour' => 8];

        $this->assertSame($target, Schedule::firstDue($job, $early, $tz));
        $this->assertSame($late, Schedule::firstDue($job, $late, $tz));
        $this->assertSame($early, Schedule::firstDue(['interval' => 300], $early, $tz));
        $this->assertSame($early, Schedule::firstDue(['interval' => 300, 'hour' => null], $early, $tz));
    }
}
