<?php

declare(strict_types=1);

namespace Freddie\Security;

use Freddie\Matcher\MatcherType;
use Freddie\Matcher\TopicMatcher;
use Freddie\Matcher\TopicMatcherStore;
use InvalidArgumentException;
use Lcobucci\JWT\UnencryptedToken;
use Psr\Http\Message\ServerRequestInterface;

use function array_is_list;
use function array_map;
use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * What an access token allows: the `authorization_details` claim (RFC 9396) or, in compatibility mode,
 * the legacy `mercure` claim.
 */
final readonly class Grants
{
    public const string AUTHORIZATION_DETAIL_TYPE = 'https://mercure.rocks/authorization-detail';
    public const string ACTION_PUBLISH = 'publish';
    public const string ACTION_SUBSCRIBE = 'subscribe';
    public const int MAX_DETAILS = 100;
    public const int MAX_DETAIL_TOPICS = 100;
    public const int MAX_LEGACY_CLAIM_MATCHERS = 1000;

    /**
     * @param AuthorizationDetail[] $details
     */
    private function __construct(
        private TopicMatcherStore $store,
        private array $details,
        private bool $hasLegacyPublishClaim = false,
        private mixed $legacyPayload = null,
    ) {
    }

    /**
     * The grants of the token the TokenExtractorMiddleware stored in the request, null if there is none.
     *
     * @throws BearerTokenException when the claims are malformed
     */
    public static function fromRequest(ServerRequestInterface $request, TopicMatcherStore $store, bool $legacy): ?self
    {
        $token = $request->getAttribute('token');

        return $token instanceof UnencryptedToken ? self::fromToken($token, $store, $legacy) : null;
    }

    /**
     * @throws BearerTokenException when the claims are malformed
     */
    public static function fromToken(UnencryptedToken $token, TopicMatcherStore $store, bool $legacy): self
    {
        $claims = $token->claims();
        $details = self::parseAuthorizationDetails($claims->get('authorization_details'), $store);
        if (!$legacy) {
            return new self($store, $details);
        }

        $mercure = $claims->get('mercure');
        if (!is_array($mercure)) {
            return new self($store, $details);
        }

        $publish = self::parseLegacyMatchers($mercure, self::ACTION_PUBLISH);
        $subscribe = self::parseLegacyMatchers($mercure, self::ACTION_SUBSCRIBE);
        if ([] !== $publish) {
            $details[] = new AuthorizationDetail(publish: true, subscribe: false, topics: $publish);
        }
        if ([] !== $subscribe) {
            $details[] = new AuthorizationDetail(publish: false, subscribe: true, topics: $subscribe);
        }

        return new self(
            $store,
            $details,
            hasLegacyPublishClaim: array_key_exists(self::ACTION_PUBLISH, $mercure),
            legacyPayload: $mercure['payload'] ?? null,
        );
    }

    /**
     * Whether the token grants the publish action on every topic.
     *
     * @param string[] $topics
     */
    public function canPublish(array $topics): bool
    {
        foreach ($topics as $topic) {
            if (!$this->grants(self::ACTION_PUBLISH, $topic)) {
                return false;
            }
        }

        return [] !== $topics;
    }

    /**
     * Whether the token grants the subscribe action on at least one topic.
     *
     * @param string[] $topics
     */
    public function canSubscribe(array $topics): bool
    {
        foreach ($topics as $topic) {
            if ($this->grants(self::ACTION_SUBSCRIBE, $topic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Protocol version 8 required the `mercure.publish` claim to publish anything, even public updates.
     */
    public function hasLegacyPublishClaim(): bool
    {
        return $this->hasLegacyPublishClaim;
    }

    /**
     * The payload of the first subscribe detail whose topics match the subscription's own matcher,
     * falling back to the legacy `mercure.payload` claim.
     */
    public function payloadFor(TopicMatcher $subscription): mixed
    {
        foreach ($this->details as $detail) {
            if (!$detail->subscribe || null === $detail->payload) {
                continue;
            }

            foreach ($detail->topics as $matcher) {
                if ($this->store->matches([$subscription->pattern], $matcher)) {
                    return $detail->payload;
                }
            }
        }

        return $this->legacyPayload;
    }

    private function grants(string $action, string $topic): bool
    {
        foreach ($this->details as $detail) {
            $granted = self::ACTION_PUBLISH === $action ? $detail->publish : $detail->subscribe;
            if (!$granted) {
                continue;
            }

            foreach ($detail->topics as $matcher) {
                if ($this->store->matches([$topic], $matcher)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return AuthorizationDetail[]
     */
    private static function parseAuthorizationDetails(mixed $claim, TopicMatcherStore $store): array
    {
        if (null === $claim) {
            return [];
        }

        if (!is_array($claim) || !array_is_list($claim)) {
            throw BearerTokenException::invalidToken('The authorization_details claim must be an array.');
        }

        $details = [];
        $topicCount = 0;
        foreach ($claim as $detail) {
            if (!is_array($detail) || self::AUTHORIZATION_DETAIL_TYPE !== ($detail['type'] ?? null)) {
                continue; // Authorization details of other types are not ours to interpret.
            }

            if (count($details) >= self::MAX_DETAILS) {
                throw self::invalidDetail(sprintf('too many authorization details (max %d)', self::MAX_DETAILS));
            }

            $actions = $detail['actions'] ?? null;
            if (!is_array($actions) || [] === $actions) {
                throw self::invalidDetail('a detail must declare at least one action');
            }

            $topics = $detail['topics'] ?? null;
            if (!is_array($topics) || [] === $topics || !array_is_list($topics)) {
                throw self::invalidDetail('a detail must declare at least one topic');
            }

            $topicCount += count($topics);
            if ($topicCount > self::MAX_DETAIL_TOPICS) {
                throw self::invalidDetail(sprintf('too many topics (max %d)', self::MAX_DETAIL_TOPICS));
            }

            $details[] = new AuthorizationDetail(
                // Unknown actions are ignored.
                publish: in_array(self::ACTION_PUBLISH, $actions, true),
                subscribe: in_array(self::ACTION_SUBSCRIBE, $actions, true),
                topics: array_map(fn (mixed $topic) => self::parseDetailTopic($topic, $store), $topics),
                payload: $detail['payload'] ?? null,
            );
        }

        return $details;
    }

    private static function parseDetailTopic(mixed $topic, TopicMatcherStore $store): TopicMatcher
    {
        // Earlier drafts allowed bare strings: reinterpreting them could change the semantics of their tokens.
        if (!is_array($topic)) {
            throw self::invalidDetail('topic entries must be objects');
        }

        $pattern = $topic['match'] ?? null;
        if (!is_string($pattern) || '' === $pattern) {
            throw self::invalidDetail('a topic entry requires a non-empty "match" property');
        }

        $matchType = $topic['match_type'] ?? MatcherType::Exact->value;
        $type = is_string($matchType) ? MatcherType::fromWire($matchType) : null;
        if (null === $type) {
            throw self::invalidDetail('unsupported topic matcher type');
        }

        $matcher = new TopicMatcher($type, $pattern);
        try {
            $store->validate($matcher);
        } catch (InvalidArgumentException $e) {
            throw self::invalidDetail($e->getMessage());
        }

        return $matcher;
    }

    /**
     * @param array<mixed> $mercure
     * @return TopicMatcher[]
     */
    private static function parseLegacyMatchers(array $mercure, string $action): array
    {
        $selectors = $mercure[$action] ?? [];
        if (!is_array($selectors) || count($selectors) > self::MAX_LEGACY_CLAIM_MATCHERS) {
            throw BearerTokenException::invalidToken(sprintf('Invalid mercure.%s claim.', $action));
        }

        $matchers = [];
        foreach ($selectors as $selector) {
            if (is_string($selector)) {
                $matchers[] = TopicMatcher::legacy($selector);
            }
        }

        return $matchers;
    }

    private static function invalidDetail(string $reason): BearerTokenException
    {
        return BearerTokenException::invalidToken('Invalid authorization_details claim: ' . $reason . '.');
    }
}
