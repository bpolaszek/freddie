<?php

namespace Freddie\Transport;

use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Freddie\Subscription\Subscription;
use Generator;
use Symfony\Component\Uid\Ulid;

interface TransportInterface
{
    /**
     * @return Generator<Update>
     */
    public function listen(?string $lastEventId = null): Generator;

    public function push(Update $update): void;

    public function registerSubscriber(Subscriber $subscriber): void;

    public function unregisterSubscriber(Subscriber $subscriber): void;

    public function getSubscription(string $subscriptionIri): ?Subscription;

    /**
     * @return iterable<Subscription>
     */
    public function getSubscriptions(?string $topic): iterable;

    public function getLastEventId(): ?Ulid;
}
