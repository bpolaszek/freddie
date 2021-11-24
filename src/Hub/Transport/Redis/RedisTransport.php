<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Hub\Transport\Redis;

use BenTools\MercurePHP\Hub\Transport\TransportInterface;
use BenTools\MercurePHP\Message\Update;
use Generator;
use React\EventLoop\Loop;

use function React\Async\await;

final class RedisTransport implements TransportInterface
{
    private string $storageKey = 'mercureUpdates';
    private bool $initialized = false;

    public function __construct(
        public readonly RedisPublisher $writer,
        public readonly RedisSubscriber $reader,
        private RedisSerializer $serializer = new RedisSerializer(),
        private int $size = 0,
        private float $trimInterval = 0.0,
    ) {
    }

    public function publish(Update $update): void
    {
        $this->writer->publish($update);
        $this->store($update);
    }

    public function subscribe(callable $callback): void
    {
        $this->reader->subscribe($callback);
    }

    public function reconciliate(string $lastEventID): Generator
    {
        if ($this->size <= 0) {
            return; // @codeCoverageIgnore
        }

        $yield = self::EARLIEST === $lastEventID;
        $payloads = await($this->writer->redis->lrange($this->storageKey, -$this->size, -1)); // @phpstan-ignore-line
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

    private function store(Update $update): void
    {
        $this->initGarbageCollector();
        if ($this->size <= 0) {
            return;
        }

        $this->writer->redis->rpush($this->storageKey, $this->serializer->serialize($update)); // @phpstan-ignore-line
    }

    private function initGarbageCollector(): void
    {
        if (true === $this->initialized) {
            return;
        }

        if ($this->trimInterval > 0) {
            Loop::addPeriodicTimer(
                $this->trimInterval,
                fn () => $this->writer->redis->ltrim($this->storageKey, -$this->size, -1) // @phpstan-ignore-line
            );
        }
        $this->initialized = true;
    }
}
