<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Clue\React\Redis\RedisClient;
use Freddie\Hub\Transport\TransportFactoryInterface;
use Freddie\Hub\Transport\TransportInterface;
use Nyholm\Dsn\DsnParser;

use function max;

final class RedisTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private RedisSerializer $serializer = new RedisSerializer(),
    ) {
    }

    public function supports(string $dsn): bool
    {
        return str_starts_with($dsn, 'redis://')
            || str_starts_with($dsn, 'rediss://');
    }

    public function create(string $dsn): TransportInterface
    {
        $parsed = DsnParser::parse($dsn);
        $redis = $subscriber = new RedisClient($dsn);
        $subscriber = new RedisClient($dsn); // Create a 2nd, blocking connection to receive updates

        return new RedisTransport(
            $subscriber,
            $redis,
            $this->serializer,
            options: [
                'size' => (int) max(0, $parsed->getParameter('size', 0)),
                'trimInterval' => (float) max(0, $parsed->getParameter('trimInterval', 0.0)),
                'pingInterval' => (float) max(0, $parsed->getParameter('pingInterval', 2.0)),
                'readTimeout' => (float) max(0, $parsed->getParameter('readTimeout', 0.0)),
                'channel' => (string) $parsed->getParameter('channel', 'mercure'),
                'key' => (string) $parsed->getParameter('key', 'mercureUpdates'),
            ],
        );
    }
}
