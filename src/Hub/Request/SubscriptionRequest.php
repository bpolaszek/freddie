<?php

declare(strict_types=1);

namespace Freddie\Hub\Request;

use Freddie\Helper\FlatQueryParser;
use Freddie\Matcher\MatcherType;
use Freddie\Matcher\TopicMatcher;
use Freddie\Matcher\TopicMatcherStore;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

use function array_key_exists;
use function array_slice;
use function count;
use function explode;
use function in_array;
use function is_array;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trigger_deprecation;
use function trim;

/**
 * The parameters of a subscription request: the topic matchers and the last event ID.
 */
final readonly class SubscriptionRequest
{
    public const string QUERY_METHOD = 'QUERY';
    public const string FORM_MEDIA_TYPE = 'application/x-www-form-urlencoded';
    public const int MAX_MATCHERS = 100;

    private const string MATCH_PARAM = 'match';
    private const string LEGACY_TOPIC_PARAM = 'topic';
    private const string LAST_EVENT_ID_PARAM = 'last_event_id';

    /**
     * Last event ID query parameters accepted in compatibility mode.
     */
    private const array LEGACY_LAST_EVENT_ID_PARAMS = ['lastEventID'];
    private const array DEPRECATED_LAST_EVENT_ID_PARAMS = [
        'Last-Event-ID',
        'Last-Event-Id',
        'last-event-id',
        'LAST-EVENT-ID',
    ];

    /**
     * @param TopicMatcher[] $matchers
     * @param bool $hasLastEventId whether a last event ID was sent at all, even empty
     */
    public function __construct(
        public array $matchers,
        public ?string $lastEventId = null,
        public bool $hasLastEventId = false,
    ) {
    }

    /**
     * @throws BadRequestHttpException
     * @throws NotAcceptableHttpException
     * @throws UnsupportedMediaTypeHttpException
     */
    public static function fromRequest(
        ServerRequestInterface $request,
        TopicMatcherStore $store,
        bool $legacy = false,
    ): self {
        $params = self::extractParams($request);

        if (!self::acceptsEventStream($request->getHeader('Accept'))) {
            throw new NotAcceptableHttpException('The request does not accept text/event-stream.');
        }

        $matchers = [];
        foreach ($params as $name => $values) {
            $type = self::matcherType((string) $name, $legacy);
            if (null === $type) {
                continue;
            }

            foreach (is_array($values) ? $values : [$values] as $pattern) {
                if (count($matchers) >= self::MAX_MATCHERS) {
                    throw new BadRequestHttpException(sprintf('Too many matchers (max %d).', self::MAX_MATCHERS));
                }

                $matcher = new TopicMatcher($type, (string) $pattern);
                try {
                    $store->validate($matcher);
                } catch (InvalidArgumentException $e) {
                    throw new BadRequestHttpException($e->getMessage(), $e);
                }
                $matchers[] = $matcher;
            }
        }

        if ([] === $matchers) {
            throw new BadRequestHttpException('Missing "match" parameter.');
        }

        [$lastEventId, $hasLastEventId] = self::extractLastEventId($request, $params, $legacy);

        return new self($matchers, $lastEventId, $hasLastEventId);
    }

    /**
     * Subscription parameters come from the query string and, for the QUERY method, from the
     * application/x-www-form-urlencoded request body (RFC 10008).
     *
     * @return array<string, mixed>
     */
    private static function extractParams(ServerRequestInterface $request): array
    {
        $parser = new FlatQueryParser();
        $params = $parser->parse($request->getUri()->getQuery());
        if (self::QUERY_METHOD !== $request->getMethod()) {
            return $params;
        }

        $contentType = trim(strtolower(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ('' === $contentType) {
            throw new BadRequestHttpException('Missing Content-Type header.');
        }

        if (self::FORM_MEDIA_TYPE !== $contentType) {
            throw new UnsupportedMediaTypeHttpException(
                sprintf('Unsupported media type, use "%s".', self::FORM_MEDIA_TYPE),
                headers: ['Accept-Query' => self::FORM_MEDIA_TYPE],
            );
        }

        foreach ($parser->parse((string) $request->getBody()) as $name => $value) {
            if (!array_key_exists($name, $params)) {
                $params[$name] = $value;
                continue;
            }
            $params[$name] = [...(array) $params[$name], ...(array) $value];
        }

        return $params;
    }

    /**
     * Matcher parameter names are case-sensitive: any other parameter in the reserved "match" namespace
     * (compared case-insensitively) is rejected.
     */
    private static function matcherType(string $name, bool $legacy): ?MatcherType
    {
        if (self::MATCH_PARAM === $name) {
            return MatcherType::Exact;
        }

        if (str_starts_with($name, self::MATCH_PARAM . '_')) {
            $type = MatcherType::fromWire(substr($name, strlen(self::MATCH_PARAM) + 1));
            if (null !== $type) {
                return $type;
            }
        }

        if (str_starts_with(strtolower($name), self::MATCH_PARAM)) {
            throw new BadRequestHttpException(sprintf('Unknown topic matcher parameter "%s".', $name));
        }

        if (self::LEGACY_TOPIC_PARAM === $name) {
            if (!$legacy) {
                throw new BadRequestHttpException(
                    'The "topic" parameter is not supported anymore, use "match" or "match_urlpattern" instead.',
                );
            }

            return MatcherType::Legacy;
        }

        return null;
    }

    /**
     * The Last-Event-ID header takes precedence over the last_event_id query parameter.
     *
     * @param array<string, mixed> $params
     * @return array{?string, bool}
     */
    private static function extractLastEventId(ServerRequestInterface $request, array $params, bool $legacy): array
    {
        $names = [self::LAST_EVENT_ID_PARAM];
        if ($legacy) {
            $names = [...$names, ...self::LEGACY_LAST_EVENT_ID_PARAMS, ...self::DEPRECATED_LAST_EVENT_ID_PARAMS];
        }

        $hasLastEventId = $request->hasHeader('Last-Event-ID');
        $header = $request->getHeaderLine('Last-Event-ID');
        if ('' !== $header) {
            return [$header, true];
        }

        foreach ($names as $name) {
            if (!array_key_exists($name, $params)) {
                continue;
            }

            $hasLastEventId = true;
            $value = is_array($params[$name]) ? $params[$name][0] : $params[$name];
            if (null === $value || '' === $value) {
                continue;
            }

            if (in_array($name, self::DEPRECATED_LAST_EVENT_ID_PARAMS, true)) {
                trigger_deprecation(
                    'freddie/mercure-x',
                    '1.0',
                    'Using "Last-Event-ID" query parameter is deprecated, use "last_event_id" instead.',
                );
            }

            return [(string) $value, true];
        }

        return [null, $hasLastEventId];
    }

    /**
     * Proactive content negotiation (RFC 9110, Section 12.5.1): the most specific media range matching
     * text/event-stream decides. An absent or blank Accept header states no preference.
     *
     * @param string[] $acceptHeaders
     */
    private static function acceptsEventStream(array $acceptHeaders): bool
    {
        $specificities = ['text/event-stream' => 3, 'text/*' => 2, '*/*' => 1];
        $bestSpecificity = 0;
        $quality = null;
        foreach ($acceptHeaders as $header) {
            foreach (explode(',', $header) as $range) {
                $parts = explode(';', $range);
                $mediaRange = strtolower(trim($parts[0]));
                if ('' === $mediaRange) {
                    continue;
                }

                $quality ??= 0.0; // At least one media range is listed.
                $specificity = $specificities[$mediaRange] ?? 0;
                if ($specificity <= $bestSpecificity) {
                    continue;
                }

                $bestSpecificity = $specificity;
                $quality = 1.0;
                foreach (array_slice($parts, 1) as $param) {
                    [$key, $value] = explode('=', $param, 2) + [1 => ''];
                    if ('q' === strtolower(trim($key))) {
                        $quality = (float) trim($value);
                    }
                }
            }
        }

        return null === $quality || $quality > 0;
    }
}
