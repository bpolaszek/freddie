<?php

namespace Freddie\Hub;

use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Freddie\Subscription\Subscription;
use Generator;
use Symfony\Component\Uid\Ulid;

interface HubInterface
{
    /**
     * @var array{allow_anonymous: bool, subscriptions: bool}
     */
    public array $options {get;} // @codingStandardsIgnoreLine

    public function getLastEventId(): ?Ulid;

    /**
     * @return Generator<Update>
     */
    public function getUpdates(Subscriber $subscriber): Generator;

    public function subscribe(Subscriber $subscriber): void;

    public function unsubscribe(Subscriber $subscriber): void;

    public function getSubscription(string $subscriptionIri): ?Subscription;

    /**
     * @return iterable<Subscription>
     */
    public function getSubscriptions(?string $topic): iterable;

    public function publish(Update $update): void;

    public function isConnectionAborted(): bool;
}
