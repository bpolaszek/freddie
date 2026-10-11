<?php

declare(strict_types=1);

namespace Freddie\Security;

use Freddie\Matcher\TopicMatcher;

/**
 * A validated Mercure authorization detail (RFC 9396).
 */
final readonly class AuthorizationDetail
{
    /**
     * @param TopicMatcher[] $topics
     */
    public function __construct(
        public bool $publish,
        public bool $subscribe,
        public array $topics,
        public mixed $payload = null,
    ) {
    }
}
