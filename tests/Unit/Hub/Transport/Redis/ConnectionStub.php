<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use Evenement\EventEmitterTrait;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

final class ConnectionStub implements ConnectionInterface
{
    use EventEmitterTrait;

    public string $received = '';

    public function getRemoteAddress()
    {
        return '';
    }

    public function getLocalAddress()
    {
        return '';
    }

    public function isReadable()
    {
        return true;
    }

    public function pause(): void
    {
    }

    public function resume(): void
    {
    }

    // @phpstan-ignore-next-line
    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        return $dest;
    }

    public function close(): void
    {
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function write($data): bool
    {
        $this->received .= $data;

        return true;
    }

    public function end($data = null): void
    {
    }
}
