<?php

declare(strict_types=1);

namespace Freddie\Subscription;

use JsonSerializable;

use function Freddie\urn;
use function json_encode;
use function sprintf;
use function urlencode;

final readonly class Subscription implements JsonSerializable
{
    public string $id;

    public function __construct(
        public Subscriber $subscriber,
        public string $topic,
    ) {
        $this->id = sprintf(
            '/.well-known/mercure/subscriptions/%s/%s',
            urlencode($this->topic),
            urlencode(urn($subscriber->id))
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
            'subscriber' => urn($this->subscriber->id),
            'topic' => $this->topic,
            'active' => $this->subscriber->active,
        ];

        if (null !== $this->subscriber->payload) {
            $output['payload'] = $this->subscriber->payload;
        }

        return $output;
    }

    public function __toString(): string
    {
        return json_encode($this);
    }
}
