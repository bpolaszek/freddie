<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Configuration;

use DateTimeZone;

final class DateTimeZoneFactory
{
    public function __invoke(): DateTimeZone
    {
        return new DateTimeZone(ini_get('date.timezone') ?: 'UTC');
    }
}
