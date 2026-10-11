<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport;

use Freddie\Message\Update;
use Generator;
use React\Promise\PromiseInterface;

interface TransportInterface
{
    public const string EARLIEST = 'earliest';

    /**
     * @return PromiseInterface<Update>
     */
    public function publish(Update $update): PromiseInterface;

    public function subscribe(callable $callback): void;

    public function unsubscribe(callable $callback): void;

    /**
     * Yields the updates published after the given event ID (all of them for "earliest").
     * The generator returns whether that event was found in the history.
     *
     * @param string $lastEventID
     * @return Generator<int, Update, mixed, bool>
     */
    public function reconciliate(string $lastEventID): Generator;
}
