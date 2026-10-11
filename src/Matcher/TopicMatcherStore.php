<?php

declare(strict_types=1);

namespace Freddie\Matcher;

use BenTools\UrlPattern\Exception\InvalidPatternException;
use BenTools\UrlPattern\URLPattern;
use InvalidArgumentException;
use Rize\UriTemplate\UriTemplate;
use Uri\WhatWg\Url;

use function array_key_first;
use function chr;
use function count;
use function Freddie\is_valid_protocol_string;
use function hexdec;
use function preg_match;
use function preg_replace_callback;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strcspn;
use function strlen;
use function strpos;
use function substr;

/**
 * Evaluates topic matchers, caching compiled URL patterns and URL pattern match results.
 */
final class TopicMatcherStore
{
    public const int MAX_PATTERN_LENGTH = 4096;

    /**
     * The base URL applied when no resource identifier is configured. ".invalid" is reserved (RFC 6761),
     * so it cannot collide with a real absolute pattern; relative ↔ relative and absolute ↔ absolute
     * matching work against any consistent base.
     */
    public const string FALLBACK_BASE_URL = 'http://mercure.invalid' . self::RESERVED_PATH;

    /**
     * The hub's path: topics in this namespace are reserved to the events the hub generates itself.
     */
    public const string RESERVED_PATH = '/.well-known/mercure';

    public readonly string $baseURL;

    /**
     * @var array<string, URLPattern>
     */
    private array $urlPatterns = [];

    /**
     * @var array<string, bool>
     */
    private array $matchResults = [];

    private UriTemplate $uriTemplate;

    public function __construct(
        ?string $baseURL = null,
        private readonly int $cacheSize = 10_000,
    ) {
        $baseURL = (string) $baseURL;
        if ('' !== $baseURL && null === Url::parse($baseURL)) {
            throw new InvalidArgumentException(sprintf('The base URL must be an absolute URL, got "%s".', $baseURL));
        }
        $this->baseURL = '' !== $baseURL ? $baseURL : self::FALLBACK_BASE_URL;
        $this->uriTemplate = new UriTemplate();
    }

    /**
     * Ensures the matcher can be evaluated, so invalid patterns surface as errors instead of silently
     * matching nothing.
     *
     * @throws InvalidArgumentException
     */
    public function validate(TopicMatcher $matcher): void
    {
        if (strlen($matcher->pattern) > self::MAX_PATTERN_LENGTH) {
            throw new InvalidArgumentException(sprintf('Pattern too long (max %d bytes).', self::MAX_PATTERN_LENGTH));
        }

        if (!is_valid_protocol_string($matcher->pattern)) {
            throw new InvalidArgumentException(
                'Topic matcher values must be valid UTF-8 without control characters.',
            );
        }

        if (MatcherType::UrlPattern === $matcher->type && TopicMatcher::WILDCARD !== $matcher->pattern) {
            try {
                $this->getUrlPattern($matcher->pattern);
            } catch (InvalidPatternException) {
                throw new InvalidArgumentException('Invalid topic matcher pattern (urlpattern).');
            }
        }
    }

    /**
     * Whether the matcher matches at least one of the given topics.
     *
     * @param string[] $topics
     */
    public function matches(array $topics, TopicMatcher $matcher): bool
    {
        if (TopicMatcher::WILDCARD === $matcher->pattern) {
            return true;
        }

        foreach ($topics as $topic) {
            if ($this->matchesTopic($topic, $matcher)) {
                return true;
            }
        }

        return false;
    }

    private function matchesTopic(string $topic, TopicMatcher $matcher): bool
    {
        return match ($matcher->type) {
            MatcherType::Exact => $topic === $matcher->pattern,
            MatcherType::UrlPattern => $this->matchesUrlPattern($topic, $matcher->pattern),
            MatcherType::Legacy => $topic === $matcher->pattern || $this->matchesUriTemplate($topic, $matcher->pattern),
        };
    }

    private function matchesUrlPattern(string $topic, string $pattern): bool
    {
        // The pattern and the topic cannot contain NUL (see validate() and the publish checks),
        // so it is an unambiguous separator.
        $key = $pattern . "\0" . $topic;
        if (isset($this->matchResults[$key])) {
            return $this->matchResults[$key];
        }

        try {
            $result = $this->getUrlPattern($pattern)->test($topic, $this->baseURL);
        } catch (InvalidPatternException) {
            $result = false;
        }

        $this->remember($this->matchResults, $key, $result);

        return $result;
    }

    private function matchesUriTemplate(string $topic, string $template): bool
    {
        return str_contains($template, '{')
            && null !== $this->uriTemplate->extract($template, $topic, true);
    }

    /**
     * @throws InvalidPatternException
     */
    private function getUrlPattern(string $pattern): URLPattern
    {
        if (isset($this->urlPatterns[$pattern])) {
            return $this->urlPatterns[$pattern];
        }

        $urlPattern = new URLPattern($pattern, $this->baseURL);
        $this->remember($this->urlPatterns, $pattern, $urlPattern);

        return $urlPattern;
    }

    /**
     * Stores a value in a bounded, first-in-first-out cache.
     *
     * @template T
     * @param array<string, T> $cache
     * @param T $value
     */
    private function remember(array &$cache, string $key, mixed $value): void
    {
        if ($this->cacheSize <= 0) {
            return;
        }

        if (count($cache) >= $this->cacheSize) {
            unset($cache[array_key_first($cache)]);
        }

        $cache[$key] = $value;
    }

    /**
     * Whether the topic path lies in the reserved "/.well-known/mercure" namespace once resolved against the
     * base URL (or, for legacy matchers that compare raw strings, before dot segments are removed).
     */
    public function addressesReservedNamespace(string $topic): bool
    {
        $resolved = Url::parse($topic, Url::parse($this->baseURL));
        $paths = [self::rawPath($topic)];
        if (null !== $resolved) {
            $paths[] = $resolved->getPath();
        }

        foreach ($paths as $path) {
            $path = self::decodeUnreservedPercentEncoding($path);
            if (self::RESERVED_PATH === $path || str_starts_with($path, self::RESERVED_PATH . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function rawPath(string $topic): string
    {
        $path = $topic;
        if (1 === preg_match('/^[a-z][a-z0-9+.\-]*:/i', $path, $scheme)) {
            $path = substr($path, strlen($scheme[0]));
            if (str_starts_with($path, '//')) {
                $slash = strpos($path, '/', 2);
                $path = false === $slash ? '' : substr($path, $slash);
            }
        }

        return substr($path, 0, strcspn($path, '?#'));
    }

    /**
     * Decodes %XX octets corresponding to RFC 3986 unreserved characters (RFC 3986 §6.2.2.2).
     */
    private static function decodeUnreservedPercentEncoding(string $path): string
    {
        return (string) preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static function (array $match): string {
                $char = chr((int) hexdec($match[1]));

                return 1 === preg_match('/^[A-Za-z0-9\-._~]$/', $char) ? $char : $match[0];
            },
            $path,
        );
    }
}
