<?php

declare(strict_types=1);

namespace Freddie\Matcher;

final readonly class TopicMatcher
{
    /**
     * The reserved wildcard, which matches every topic regardless of the matcher type.
     */
    public const string WILDCARD = '*';

    public function __construct(
        public MatcherType $type,
        public string $pattern,
    ) {
    }

    public static function exact(string $pattern): self
    {
        return new self(MatcherType::Exact, $pattern);
    }

    public static function urlPattern(string $pattern): self
    {
        return new self(MatcherType::UrlPattern, $pattern);
    }

    public static function legacy(string $pattern): self
    {
        return new self(MatcherType::Legacy, $pattern);
    }
}
