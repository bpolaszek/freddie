<?php

declare(strict_types=1);

namespace Freddie\Hub;

use Freddie\Matcher\TopicMatcherStore;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Generator;
use React\Promise\PromiseInterface;

interface HubInterface
{
    public function getOption(string $name): mixed;

    /**
     * Whether the hub runs in compatibility mode with the protocol version 8 (Mercure 0.x).
     */
    public function isLegacyProtocol(): bool;

    public function getTopicMatcherStore(): TopicMatcherStore;

    /**
     * @return PromiseInterface<Update>
     */
    public function publish(Update $update): PromiseInterface;

    public function subscribe(Subscriber $subscriber): void;

    public function unsubscribe(Subscriber $subscriber): void;

    /**
     * The subscribers connected to this process.
     *
     * @return Subscriber[]
     */
    public function getSubscribers(): array;

    /**
     * Yields the updates published after the given event ID. The generator returns whether that event was found.
     *
     * @param string $lastEventID
     * @return Generator<int, Update, mixed, bool|null>
     */
    public function reconciliate(string $lastEventID): Generator;
}
