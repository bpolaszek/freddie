<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Tests\Unit\Hub\Transport\Redis;

use ArrayObject;
use Clue\React\Redis\Client;
use Evenement\EventEmitterTrait;
use Pest\Exceptions\ShouldNotHappen;

use function abs;
use function array_splice;
use function count;
use function React\Async\async;

final class RedisClientStub implements Client
{
    use EventEmitterTrait;

    public array $subscribedChannels = [];
    public readonly ArrayObject $storage;

    public function __construct()
    {
        $this->storage = new ArrayObject();
    }

    public function subscribe(string $channel): void
    {
        $this->subscribedChannels[] = $channel;
    }

    public function publish(string $channel, string $payload): void
    {
        $this->emit('message', [$channel, $payload]);
    }

    public function rpush(string $key, string ...$items): void
    {
        foreach ($items as $item) {
            $this->storage[$key][] = $item;
        }
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

        return async(fn () => array_splice($items, $firstIndex, $length));
    }

    public function ltrim(string $key, int $from, int $to)
    {
        return async(fn () => $this->lrange($key, $from, $to))
            ->then(fn (array $items) => $this->storage[$key] = $items);
    }

    public function __call($name, $args)
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
}
