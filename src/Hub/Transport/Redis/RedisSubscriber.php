<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Freddie\Message\Update;
use Clue\React\Redis\Client;
use Symfony\Component\Serializer\SerializerInterface;

final class RedisSubscriber
{
    private string $channel = 'mercure';
    private bool $subscribed = false;

    public function __construct(
        public readonly Client $redis,
        private RedisSerializer $serializer = new RedisSerializer(),
    ) {
    }

    public function subscribe(callable $callback): void
    {
        if (false === $this->subscribed) {
            $this->redis->subscribe($this->channel); // @phpstan-ignore-line
            $this->subscribed = true;
        }
        $this->redis->on('message', function (string $channel, string $payload) use ($callback) {
            $callback($this->serializer->deserialize($payload));
        });
    }
}
