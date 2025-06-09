<?php

namespace Freddie\Hub;

use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Freddie\Subscription\Subscription;
use Freddie\Transport\TransportInterface;
use Generator;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Ulid;

use function connection_aborted;

final readonly class Hub implements HubInterface
{
    /**
     * @var array{allow_anonymous: bool, subscriptions: bool}
     */
    public private(set) array $options;

    /**
     * @param array{allow_anonymous?: bool, subscriptions?: bool} $options
     */
    public function __construct(
        private(set) TransportInterface $transport,
        array $options = [],
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(HubOptions::DEFAULTS);
        $resolver->setAllowedTypes('allow_anonymous', 'bool');
        $resolver->setAllowedTypes('subscriptions', 'bool');
        $this->options = $resolver->resolve($options);
    }

    public function publish(Update $update): void
    {
        $this->transport->push($update);
    }

    public function getUpdates(Subscriber $subscriber): Generator
    {
        $allowsAnonymous = $this->options['allow_anonymous'];
        foreach ($this->transport->listen($subscriber->lastEventId) as $update) {
            if (!$update->canBeReceived($subscriber->subscribedTopics, $subscriber->allowedTopics, $allowsAnonymous)) {
                continue;
            }
            yield $update;
        }
    }

    public function subscribe(Subscriber $subscriber): void
    {
        $subscriptionsEnabled = $this->options['subscriptions'];
        if (!$subscriptionsEnabled) {
            return;
        }

        $this->transport->registerSubscriber($subscriber);
        foreach ($subscriber->subscriptions as $subscription) {
            $this->publish(new Update($subscription->id, new Message(data: (string) $subscription, private: true)));
        }
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        $subscriptionsEnabled = $this->options['subscriptions'];
        if (!$subscriptionsEnabled) {
            return;
        }

        $subscriber->active = false;
        $this->transport->unregisterSubscriber($subscriber);

        foreach ($subscriber->subscriptions as $subscription) {
            $this->publish(new Update($subscription->id, new Message(data: (string) $subscription, private: true)));
        }
    }

    public function getLastEventId(): ?Ulid
    {
        return $this->transport->getLastEventId();
    }

    public function getSubscription(string $subscriptionIri): ?Subscription
    {
        return $this->transport->getSubscription($subscriptionIri);
    }

    public function getSubscriptions(?string $topic): iterable
    {
        return $this->transport->getSubscriptions($topic);
    }

    public function isConnectionAborted(): bool
    {
        return (bool) connection_aborted();
    }
}
