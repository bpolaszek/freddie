<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Clue\React\Redis\Client;
use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Update;
use Generator;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

use function React\Async\await;
use function React\Promise\resolve;

final class RedisTransport implements TransportInterface
{
    private string $channel = 'mercure';
    private string $storageKey = 'mercureUpdates';
    private bool $initialized = false;

    public function __construct(
        public readonly Client $subscriber,
        public readonly Client $redis,
        private RedisSerializer $serializer = new RedisSerializer(),
        private EventEmitterInterface $eventEmitter = new EventEmitter(),
        private int $size = 0,
        private float $trimInterval = 0.0,
    ) {
    }

    public function subscribe(callable $callback): void
    {
        $this->init();
        $this->eventEmitter->on('mercureUpdate', $callback);
    }

    public function unsubscribe(callable $callback): void
    {
        $this->eventEmitter->removeListener('mercureUpdate', $callback);
    }

    public function publish(Update $update): PromiseInterface
    {
        $this->init();
        $payload = $this->serializer->serialize($update);

        return $this->redis->publish($this->channel, $payload) // @phpstan-ignore-line
            ->then(fn() => $this->store($update))
            ->then(fn() => $update);
    }

    public function reconciliate(string $lastEventID): Generator
    {
        $this->init();
        if ($this->size <= 0) {
            return; // @codeCoverageIgnore
        }

        $yield = self::EARLIEST === $lastEventID;
        $payloads = await($this->redis->lrange($this->storageKey, -$this->size, -1)); // @phpstan-ignore-line
        foreach ($payloads as $payload) {
            $update = $this->serializer->deserialize($payload);
            if ($yield) {
                yield $update;
            }
            if ($update->message->id === $lastEventID) {
                $yield = true;
            }
        }
    }

    private function store(Update $update): PromiseInterface
    {
        $this->init();
        if ($this->size <= 0) {
            return resolve();
        }

        return $this->redis->rpush($this->storageKey, $this->serializer->serialize($update)); // @phpstan-ignore-line
    }

    private function init(): void
    {
        if (true === $this->initialized) {
            return;
        }

        $this->subscriber->subscribe($this->channel); // @phpstan-ignore-line
        $this->subscriber->on('message', function (string $channel, string $payload) {
            $this->eventEmitter->emit('mercureUpdate', [$this->serializer->deserialize($payload)]);
        });

        if ($this->trimInterval > 0) {
            Loop::addPeriodicTimer(
                $this->trimInterval,
                fn () => $this->redis->ltrim($this->storageKey, -$this->size, -1) // @phpstan-ignore-line
            );
        }
        $this->initialized = true;
    }
}
