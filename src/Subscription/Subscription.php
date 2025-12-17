<?php

declare(strict_types=1);

namespace Freddie\Subscription;

final readonly class Subscription
{
    public function __construct(
        public Subscriber $subscriber,
        public string $topic,
    ) {
    }
}
