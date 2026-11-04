<?php

declare(strict_types=1);

namespace Freddie\Subscription;

use Freddie\Matcher\TopicMatcher;
use Freddie\Matcher\TopicMatcherStore;
use Freddie\Message\Update;
use Freddie\Security\Grants;
use Symfony\Component\Uid\Uuid;

use function array_map;

final class Subscriber
{
    public readonly string $id;

    /**
     * @var Subscription[]
     */
    public readonly array $subscriptions;

    /**
     * @var callable
     */
    private $callback;

    /**
     * @param TopicMatcher[] $matchers
     * @param Grants|null $grants null for anonymous subscribers
     */
    public function __construct(
        public readonly array $matchers,
        private readonly TopicMatcherStore $store = new TopicMatcherStore(),
        public readonly ?Grants $grants = null,
        ?string $id = null,
        public bool $active = true,
    ) {
        $this->id = $id ?? 'urn:uuid:' . Uuid::v4()->toRfc4122();
        $this->subscriptions = array_map(
            fn (TopicMatcher $matcher) => new Subscription($this, $matcher, $grants?->payloadFor($matcher)),
            $this->matchers,
        );
    }

    /**
     * Whether one of the update's topics is subscribed to and, for a private update, whether the subscriber
     * is authorized to receive it.
     */
    public function canReceive(Update $update): bool
    {
        // Cheap check first: matching topics against URL patterns is not.
        if ($update->message->private && null === $this->grants) {
            return false;
        }

        if (!$this->matchesAny($update->topics)) {
            return false;
        }

        return !$update->message->private || $this->grants->canSubscribe($update->topics);
    }

    /**
     * @param string[] $topics
     */
    private function matchesAny(array $topics): bool
    {
        foreach ($this->matchers as $matcher) {
            if ($this->store->matches($topics, $matcher)) {
                return true;
            }
        }

        return false;
    }

    public function setCallback(callable $callback): void
    {
        $this->callback = $callback;
    }

    /**
     * @param mixed ...$args
     */
    public function __invoke(...$args): void
    {
        ($this->callback)(...$args);
    }
}
