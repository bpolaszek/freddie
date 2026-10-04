<?php

declare(strict_types=1);

namespace Freddie\Subscription;

use Freddie\Matcher\MatcherType;
use Freddie\Matcher\TopicMatcher;
use Freddie\Matcher\TopicMatcherStore;

use function json_encode;
use function rawurlencode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class Subscription
{
    public const string PATH = TopicMatcherStore::RESERVED_PATH . '/subscriptions';

    public function __construct(
        public Subscriber $subscriber,
        public TopicMatcher $matcher,
        public mixed $payload = null,
    ) {
    }

    /**
     * The subscription URL, which is also the topic of its subscription events:
     * /.well-known/mercure/subscriptions/{match_type}/{match}/{subscriber}.
     * Protocol version 8 subscriptions keep the /.well-known/mercure/subscriptions/{topic}/{subscriber} shape.
     */
    public function getId(): string
    {
        $segments = MatcherType::Legacy === $this->matcher->type
            ? [$this->matcher->pattern, $this->subscriber->id]
            : [$this->matcher->type->value, $this->matcher->pattern, $this->subscriber->id];

        $id = self::PATH;
        foreach ($segments as $segment) {
            // Only RFC 3986 unreserved characters are kept as is.
            $id .= '/' . rawurlencode($segment);
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $active): array
    {
        $document = [
            'id' => $this->getId(),
            'type' => 'subscription',
            'subscriber' => $this->subscriber->id,
        ];

        if (MatcherType::Legacy === $this->matcher->type) {
            $document['topic'] = $this->matcher->pattern;
        } else {
            $document['match'] = $this->matcher->pattern;
            $document['match_type'] = $this->matcher->type->value;
        }

        $document['active'] = $active;
        if (null !== $this->payload) {
            $document['payload'] = $this->payload;
        }

        return $document;
    }

    /**
     * Compact JSON: every newline would cost a further "data:" line once framed as a server-sent event.
     */
    public function toJson(bool $active): string
    {
        return json_encode(
            $this->toArray($active),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
