<?php

namespace Kanboard\Plugin\Foreman\Model;

use DateTimeImmutable;
use DateTimeZone;
use DateInvalidTimeZoneException;
use Pimple\Container;

class Schedule
{
    public static function tz(Container $c): DateTimeZone
    {
        $timezone = $c['configModel']->get('application_timezone', 'UTC');
        try {
            return new DateTimeZone($timezone ?: 'UTC');
        } catch (DateInvalidTimeZoneException) {
            return new DateTimeZone('UTC');
        }
    }

    public static function firstDue(array $job, int $now, DateTimeZone $tz): int
    {
        $hour = $job['hour'] ?? null;
        if ($hour === null) {
            return $now;
        }

        $localNow = (new DateTimeImmutable('@'.$now))->setTimezone($tz);
        return max($now, $localNow->setTime($hour, 0)->getTimestamp());
    }

    public static function nextDue(array $job, string $status, int $now, DateTimeZone $tz): int
    {
        if ($status === 'more') {
            return $now;
        }
        if ($status === 'failed') {
            return $now + min($job['interval'], 900);
        }

        $hour = $job['hour'] ?? null;
        if ($hour === null) {
            return $now + $job['interval'];
        }

        $localNow = (new DateTimeImmutable('@'.$now))->setTimezone($tz);
        $today = $localNow->setTime($hour, 0)->getTimestamp();
        if ($today > $now) {
            return $today;
        }

        return $localNow->modify('+1 day')->setTime($hour, 0)->getTimestamp();
    }
}
