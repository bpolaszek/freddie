<?php

declare(strict_types=1);

namespace Freddie\Matcher;

/**
 * How a topic matcher pattern is evaluated.
 *
 * The backed values are the wire format: they appear in subscribe query parameters
 * (`match_<type>`), in the `match_type` member of authorization details and in subscription URLs.
 */
enum MatcherType: string
{
    /** Byte-for-byte comparison. */
    case Exact = 'exact';

    /** WHATWG URL Pattern, resolved against the hub's base URL. */
    case UrlPattern = 'urlpattern';

    /**
     * Protocol version 8 topic selector (exact match or URI Template), only available in compatibility mode.
     * The leading underscore keeps it out of the protocol namespace.
     */
    case Legacy = '_legacy';

    /**
     * Matcher types addressable from the wire (query parameters, tokens, subscription URLs).
     */
    public static function fromWire(string $value): ?self
    {
        $type = self::tryFrom($value);

        return self::Legacy === $type ? null : $type;
    }
}
