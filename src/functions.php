<?php

declare(strict_types=1);

namespace Freddie;

use React\Promise\PromiseInterface;

use function in_array;
use function is_string;
use function preg_match;
use function React\Promise\Timer\timeout;
use function settype;
use function strtolower;
use function trim;

/**
 * Whether the string satisfies the protocol constraints on topics, matcher patterns and the id/type fields:
 * valid UTF-8, without control characters (C0, DEL, C1) nor Unicode format characters (bidirectional and
 * zero-width controls, which enable identifier spoofing).
 */
function is_valid_protocol_string(string $value): bool
{
    return 0 === preg_match('/[\p{Cc}\p{Cf}]/u', $value);
}

function is_truthy(mixed $value): bool
{
    return in_array(strtolower((string) $value), ['yes', 'on', 'y', 'true', '1'], true);
}

function nullify(mixed $value, ?string $cast = null): mixed
{
    if (null === $value) {
        return null;
    }

    if (is_string($value) && '' === trim($value)) {
        return null;
    }

    if ($cast) {
        settype($value, $cast);
    }

    return $value;
}

/**
 * @internal
 * @template T
 * @param PromiseInterface<T> $promise
 * @return PromiseInterface<T>
 */
function maybeTimeout(PromiseInterface $promise, float $time = 0.0): PromiseInterface
{
    return 0.0 === $time ? $promise : timeout($promise, $time);
}
