<?php

declare(strict_types=1);

namespace Freddie;

use Freddie\Helper\TopicHelper;
use InvalidArgumentException;
use Rize\UriTemplate\UriTemplate;
use Symfony\Component\Uid\Ulid;

use function in_array;
use function is_string;
use function settype;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

function topic(string $topic): TopicHelper
{
    static $helper;
    $helper ??= new TopicHelper(new UriTemplate());

    return $helper->with($topic);
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

function urn(Ulid $ulid): string
{
    return 'urn:uuid:' . $ulid->toRfc4122();
}

function fromUrn(string $urn): Ulid
{
    if (!str_starts_with($urn, 'urn:uuid:')) {
        throw new InvalidArgumentException('Invalid URN format for Ulid');
    }

    return Ulid::fromRfc4122(substr($urn, 9));
}
