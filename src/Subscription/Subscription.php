<?php

declare(strict_types=1);

namespace Freddie\Subscription;

final class Subscription
{
    public function __construct(
        public readonly Subscriber $subscriber,
        public readonly string $topic,
    ) {
    }

    public function __toString(): string
    {
        return $this->topic;
    }
}
