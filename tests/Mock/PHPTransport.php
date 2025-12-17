<?php

declare(strict_types=1);

namespace Freddie\Tests\Mock;

use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Freddie\Subscription\Subscription;
use Freddie\Transport\TransportInterface;
use Generator;
use Symfony\Component\Uid\Ulid;

use function array_filter;
use function array_find;
use function array_values;

final class PHPTransport implements TransportInterface
{
    private ?string $lastEventIdBeforeSubscription = null;
    private Ulid|null $lastEventId = null;

    /**
     * @param Update[] $updates
     * @param Subscriber[] $subscribers
     */
    public function __construct(
        public array $updates = [],
        public array $subscribers = [],
    ) {
        foreach ($this->updates as $update) {
            $this->lastEventIdBeforeSubscription = (string) $update->message->id;
        }
    }

    public function listen(?string $lastEventId = null): Generator
    {
        $shouldYield = false;
        foreach ($this->updates as $update) {
            if ('earliest' === $lastEventId) {
                $shouldYield = true;
            } elseif (null === $lastEventId) {
                $shouldYield = $shouldYield || (string) $update->message->id > $this->lastEventIdBeforeSubscription;
            } else {
                $shouldYield = $shouldYield || (string) $update->message->id > $lastEventId;
            }

            if ($shouldYield) {
                yield $update;
            }
        }
    }

    public function push(Update $update): void
    {
        $this->updates[] = $update;
        $this->lastEventId = $update->message->id;
    }

    public function registerSubscriber(Subscriber $subscriber): void
    {
        $this->subscribers[] = $subscriber;
    }

    public function unregisterSubscriber(Subscriber $subscriber): void
    {
        $this->subscribers = array_values(
            array_filter(
                $this->subscribers,
                fn(Subscriber $s) => 0 === $s->id->compare($subscriber->id)
            )
        );
    }

    public function getSubscription(string $subscriptionIri): ?Subscription
    {
        $subscriptions = [...$this->getSubscriptions(null)];

        return array_find($subscriptions, fn(Subscription $s) => $s->id === $subscriptionIri);
    }

    /**
     * @return Subscription[]
     */
    public function getSubscriptions(?string $topic): iterable
    {
        foreach ($this->subscribers as $subscriber) {
            foreach ($subscriber->subscriptions as $subscription) {
                if (null === $topic || $topic === $subscription->topic) {
                    yield $subscription;
                }
            }
        }
    }

    public function getLastEventId(): ?Ulid
    {
        return $this->lastEventId;
    }
}
