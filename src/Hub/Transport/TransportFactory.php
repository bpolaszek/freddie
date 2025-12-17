<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport;

use Freddie\Hub\Transport\PHP\PHPTransportFactory;
use Freddie\Hub\Transport\Redis\RedisTransportFactory;
use InvalidArgumentException;

final readonly class TransportFactory
{
    /**
     * @param iterable<TransportFactoryInterface> $factories
     */
    public function __construct(
        private iterable $factories = [
            new PHPTransportFactory(),
            new RedisTransportFactory()
        ],
    ) {
    }

    public function create(string $dsn): TransportInterface
    {
        foreach ($this->factories as $factory) {
            if ($factory->supports($dsn)) {
                return $factory->create($dsn);
            }
        }

        throw new InvalidArgumentException('Invalid transport DSN.');
    }
}
