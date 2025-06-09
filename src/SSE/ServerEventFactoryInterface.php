<?php

namespace Freddie\SSE;

use Freddie\Message\Update;
use Symfony\Component\HttpFoundation\ServerEvent;

interface ServerEventFactoryInterface
{
    public function createServerEvent(Update $update): ServerEvent;
}
