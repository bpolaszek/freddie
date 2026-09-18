<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Controller;

use Evenement\EventEmitterTrait;
use LogicException;
use Pest\Exceptions\ShouldNotHappen;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * Models a "gone" client (a half-open TCP socket whose peer vanished without
 * FIN/RST): the first write to the dead peer fails and, like React's
 * WritableResourceStream::handleWrite(), closes the stream (emitting 'close').
 */
final class DeadStreamStub implements WritableStreamInterface, ReadableStreamInterface
{
    use EventEmitterTrait;

    /**
     * @var array<string>
     */
    public array $writes = [];

    private bool $closed = false;

    public function write($data): bool
    {
        $this->writes[] = $data;
        $this->close();

        return false;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
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
        return !$this->closed;
    }

    public function end($data = null): void
    {
        throw new ShouldNotHappen(new LogicException(__METHOD__));
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->emit('close');
    }
}
