<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\PHP;

use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Update;
use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;
use Generator;
use React\Promise\PromiseInterface;

use function array_shift;
use function count;
use function React\Promise\resolve;

final class PHPTransport implements TransportInterface
{
    /**
     * @var Update[]
     */
    private array $updates = [];

    public function __construct(
        private EventEmitterInterface $eventEmitter = new EventEmitter(),
        private int $size = 0,
    ) {
    }

    public function publish(Update $update): PromiseInterface
    {
        $this->store($update);
        $this->eventEmitter->emit('mercureUpdate', [$update]);

        return resolve($update);
    }

    public function subscribe(callable $callback): void
    {
        $this->eventEmitter->on('mercureUpdate', $callback);
    }

    public function reconciliate(string $lastEventID): Generator
    {
        $yield = self::EARLIEST === $lastEventID;
        foreach ($this->updates as $update) {
            if ($yield) {
                yield $update;
            }
            if ($update->message->id === $lastEventID) {
                $yield = true;
            }
        }
    }

    private function store(Update $update): void
    {
        if ($this->size <= 0) {
            return;
        }

        if (count($this->updates) >= $this->size) {
            array_shift($this->updates);
        }

        $this->updates[] = $update;
    }
}
