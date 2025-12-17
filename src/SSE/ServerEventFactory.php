<?php

namespace Freddie\SSE;

use Freddie\Message\Update;
use Symfony\Component\HttpFoundation\ServerEvent;

final readonly class ServerEventFactory implements ServerEventFactoryInterface
{
    public function createServerEvent(Update $update): ServerEvent
    {
        return new ServerEvent(
            $update->message->data,
            $update->message->event,
            $update->message->retry,
            (string) $update->message->id,
        );
    }
}
