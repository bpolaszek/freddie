<?php

declare(strict_types=1);

namespace Freddie\Subscription;

use JsonSerializable;

use function sprintf;
use function urlencode;

final class Subscription implements JsonSerializable
{
    public readonly string $id;

    public function __construct(
        public readonly Subscriber $subscriber,
        public readonly string $topic,
    ) {
        $this->id = sprintf(
            '/.well-known/mercure/subscriptions/%s/%s',
            urlencode($this->topic),
            urlencode((string) $subscriber->id)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $output = [
            'id' => $this->id,
            'type' => 'Subscription',
            'subscriber' => (string) $this->subscriber->id,
            'topic' => $this->topic,
            'active' => $this->subscriber->active,
        ];

        if (null !== $this->subscriber->payload) {
            $output['payload'] = $this->subscriber->payload;
        }

        return $output;
    }
}
