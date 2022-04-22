<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport;

use Freddie\Message\Update;
use Generator;
use React\Promise\PromiseInterface;

interface TransportInterface
{
    public const EARLIEST = 'earliest';

    /**
     * @return PromiseInterface<Update>
     */
    public function publish(Update $update): PromiseInterface;

    public function subscribe(callable $callback): void;

    public function unsubscribe(callable $callback): void;

    /**
     * @param string $lastEventID
     * @return Generator<Update>
     */
    public function reconciliate(string $lastEventID): Generator;
}
