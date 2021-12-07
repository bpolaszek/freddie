<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport;

use Freddie\Message\Update;
use Generator;

interface TransportInterface
{
    public const EARLIEST = 'earliest';

    public function publish(Update $update): void;

    public function subscribe(callable $callback): void;

    /**
     * @param string $lastEventID
     * @return Generator<Update>
     */
    public function reconciliate(string $lastEventID): Generator;
}
