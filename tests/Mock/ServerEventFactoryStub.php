<?php

namespace Freddie\Tests\Mock;

use Freddie\Message\Update;
use Freddie\SSE\ServerEventFactory;
use Freddie\SSE\ServerEventFactoryInterface;
use Symfony\Component\HttpFoundation\ServerEvent;
use WeakMap;

final class ServerEventFactoryStub implements ServerEventFactoryInterface
{
    /**
     * @var Update[]
     */
    public private(set) array $updates = [];

    /**
     * @var WeakMap<Update, ServerEvent>
     */
    public private(set) WeakMap $events;

    public function __construct(
        private readonly ServerEventFactoryInterface $factory = new ServerEventFactory(),
    ) {
        $this->events = new WeakMap();
    }

    public function reset(): void
    {
        $this->updates = [];
        $this->events = new WeakMap();
    }

    public function createServerEvent(Update $update): ServerEvent
    {
        $event = $this->factory->createServerEvent($update);

        $this->updates[] = $update;
        $this->events[$update] = $event;

        return $event;
    }
}
