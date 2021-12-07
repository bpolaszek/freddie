<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Freddie\Message\Update;
use Clue\React\Redis\Client;

final class RedisPublisher
{
    private string $channel = 'mercure';

    public function __construct(
        public readonly Client $redis,
        private RedisSerializer $serializer = new RedisSerializer(),
    ) {
    }

    public function publish(Update $update): void
    {
        $payload = $this->serializer->serialize($update);
        $this->redis->publish($this->channel, $payload); // @phpstan-ignore-line
    }
}
