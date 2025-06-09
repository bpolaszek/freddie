<?php

declare(strict_types=1);

namespace Freddie\Subscription;

use JsonSerializable;
use Symfony\Component\Uid\Ulid;

use function array_map;

final class Subscriber implements JsonSerializable
{
    public bool $active = true;

    /**
     * @var Subscription[]
     */
    public readonly array $subscriptions;

    /**
     * @param string[] $subscribedTopics
     * @param string[]|null $allowedTopics
     */
    public function __construct(
        public readonly array $subscribedTopics = ['*'],
        public readonly ?array $allowedTopics = null,
        public readonly ?string $lastEventId = null,
        public readonly mixed $payload = null,
        public readonly Ulid $id = new Ulid(),
    ) {
        $this->subscriptions = array_map(
            fn(string $topic) => new Subscription($this, $topic),
            $this->subscribedTopics,
        );
    }

    public function __clone(): void
    {
        $this->subscriptions = array_map( // @phpstan-ignore property.readOnlyAssignNotInConstructor
            fn(string $topic) => new Subscription($this, $topic),
            $this->subscribedTopics,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => (string) $this->id,
            'subscriptions' => array_map(
                fn(Subscription $subscription) => $subscription->jsonSerialize(),
                $this->subscriptions,
            ),
        ];
    }
}
