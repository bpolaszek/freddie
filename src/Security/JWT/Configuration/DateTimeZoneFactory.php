<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Configuration;

use DateTimeZone;

/**
 * @codeCoverageIgnore
 */
final readonly class DateTimeZoneFactory
{
    public function __invoke(): DateTimeZone
    {
        static $dateTimeZone;

        return $dateTimeZone ??= new DateTimeZone(ini_get('date.timezone') ?: 'UTC');
    }
}
