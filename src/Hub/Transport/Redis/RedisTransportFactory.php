<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Freddie\Hub\Transport\TransportFactoryInterface;
use Freddie\Hub\Transport\TransportInterface;
use Clue\React\Redis\Factory;
use Nyholm\Dsn\DsnParser;

use function max;

final class RedisTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private Factory $factory = new Factory(),
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
        $publisher = $this->factory->createLazyClient($dsn);
        $listener = $this->factory->createLazyClient($dsn);

        return new RedisTransport(
            new RedisPublisher($publisher, $this->serializer),
            new RedisSubscriber($listener, $this->serializer),
            $this->serializer,
            size: (int) max(0, $parsed->getParameter('size', 0)),
            trimInterval: (float) max(0, $parsed->getParameter('trimInterval', 0.0)),
        );
    }
}
