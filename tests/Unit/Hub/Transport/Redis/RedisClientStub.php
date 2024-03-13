<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use ArrayObject;
use Clue\React\Redis\RedisClient;
use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;
use Pest\Exceptions\ShouldNotHappen;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;

use function abs;
use function array_splice;
use function count;
use function React\Async\async;
use function React\Promise\resolve;

final class RedisClientStub extends RedisClient
{
    public array $subscribedChannels = [];

    public function __construct(
        public ArrayObject $storage = new ArrayObject(),
        private readonly EventEmitterInterface $eventEmitter = new EventEmitter(),
    ) {
        $connectorStub = new class implements ConnectorInterface {
            public function connect($uri)
            {
                return resolve(new ConnectionStub());
            }
        };
        parent::__construct('redis://127.0.0.1', $connectorStub);
    }

    public function subscribe(string $channel): void
    {
        $this->subscribedChannels[] = $channel;
    }

    public function publish(string $channel, string $payload): PromiseInterface
    {
        $this->emit('message', [$channel, $payload]);

        return resolve(true);
    }

    public function rpush(string $key, string ...$items): PromiseInterface
    {
        foreach ($items as $item) {
            $this->storage[$key][] = $item;
        }

        return resolve(true);
    }

    public function lrange(string $key, int $from, int $to)
    {
        $items = $this->storage[$key] ?? [];
        $firstIndex = $from;
        $length = count($items);
        if ($to < 0) {
            $length = $length - abs($to) + 1;
        }

        $length -= $from;

        return async(fn () => array_splice($items, $firstIndex, $length))();
    }

    public function ltrim(string $key, int $from, int $to)
    {
        return async(fn () => $this->lrange($key, $from, $to))()
            ->then(fn (array $items) => $this->storage[$key] = $items);
    }

    public function __call($name, $args): PromiseInterface
    {
        throw new ShouldNotHappen(new \LogicException(__METHOD__));
    }

    public function end(): void
    {
        throw new ShouldNotHappen(new \LogicException(__METHOD__));
    }

    public function close(): void
    {
        throw new ShouldNotHappen(new \LogicException(__METHOD__));
    }

    public function on($event, callable $listener): void
    {
        $this->eventEmitter->on(...\func_get_args());
    }

    public function once($event, callable $listener): void
    {
        $this->eventEmitter->once(...\func_get_args());
    }

    public function removeListener($event, callable $listener): void
    {
        $this->eventEmitter->removeListener(...\func_get_args());
    }

    public function removeAllListeners($event = null): void
    {
        $this->eventEmitter->removeAllListeners(...\func_get_args());
    }

    public function listeners($event = null): array
    {
        return $this->eventEmitter->listeners(...\func_get_args());
    }

    public function emit($event, array $arguments = []): void
    {
        $this->eventEmitter->emit(...\func_get_args());
    }
}
