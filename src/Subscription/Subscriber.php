<?php

declare(strict_types=1);

namespace Freddie\Subscription;

use Symfony\Component\Uid\Ulid;

use function array_map;

final class Subscriber
{
    /**
     * @var Subscription[]
     */
    public readonly array $subscriptions;

    /**
     * @var callable
     */
    private $callback;

    /**
     * @param string[] $topics
     */
    public function __construct(
        public readonly array $topics,
        public readonly mixed $payload = null,
        public readonly Ulid $id = new Ulid(),
        public bool $active = true,
    ) {
        $this->subscriptions = array_map(
            fn(string $topic) => new Subscription($this, $topic),
            $this->topics,
        );
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

    public function __toString(): string
    {
        return sprintf(
            '%s on [%s]',
            $this->id,
            implode(',', $this->subscriptions)
        );
    }
}
