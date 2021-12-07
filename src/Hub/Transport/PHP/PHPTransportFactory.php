<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\PHP;

use Freddie\Hub\Transport\TransportFactoryInterface;
use Freddie\Hub\Transport\TransportInterface;
use Evenement\EventEmitter;
use Evenement\EventEmitterInterface;
use Nyholm\Dsn\DsnParser;

final class PHPTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private EventEmitterInterface $eventEmitter = new EventEmitter(),
    ) {
    }

    public function supports(string $dsn): bool
    {
        return str_starts_with($dsn, 'php://');
    }

    public function create(string $dsn): TransportInterface
    {
        $parsed = DsnParser::parse($dsn);

        return new PHPTransport(
            $this->eventEmitter,
            (int) $parsed->getParameter('size', 0)
        );
    }
}
