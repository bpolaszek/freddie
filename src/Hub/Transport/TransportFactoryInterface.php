<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport;

interface TransportFactoryInterface
{
    public function supports(string $dsn): bool;

    public function create(string $dsn): TransportInterface;
}
