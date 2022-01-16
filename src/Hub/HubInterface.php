<?php

declare(strict_types=1);

namespace Freddie\Hub;

use Freddie\Hub\Transport\TransportInterface;

interface HubInterface extends TransportInterface
{
    public function getOption(string $name): mixed;
}
