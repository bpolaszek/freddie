<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Controller;

use Evenement\EventEmitterTrait;
use LogicException;
use Pest\Exceptions\ShouldNotHappen;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

final class ThroughStreamStub implements WritableStreamInterface, ReadableStreamInterface
{
    use EventEmitterTrait;

    public array $storage = [];

    public function write($data)
    {
        $this->storage[] = $data;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function pause(): void
    {
        throw new ShouldNotHappen(new LogicException(__METHOD__));
    }

    public function resume(): void
    {
        throw new ShouldNotHappen(new LogicException(__METHOD__));
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): ?WritableStreamInterface
    {
        throw new ShouldNotHappen(new LogicException(__METHOD__));
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function end($data = null): void
    {
        throw new ShouldNotHappen(new LogicException(__METHOD__));
    }

    public function close(): void
    {
        $this->emit('close');
    }
}
