<?php

declare(strict_types=1);

namespace Freddie\Hub;

use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Generator;
use React\Promise\PromiseInterface;

interface HubInterface
{
    public function getOption(string $name): mixed;

    /**
     * @return PromiseInterface<Update>
     */
    public function publish(Update $update): PromiseInterface;

    public function subscribe(Subscriber $subscriber): void;

    public function unsubscribe(Subscriber $subscriber): void;

    /**
     * @param string $lastEventID
     * @return Generator<Update>
     */
    public function reconciliate(string $lastEventID): Generator;
}
