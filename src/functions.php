<?php

declare(strict_types=1);

namespace Freddie;

use Freddie\Helper\FlatQueryParser;
use Freddie\Helper\TopicHelper;
use Psr\Http\Message\ServerRequestInterface;

use function BenTools\QueryString\query_string;
use function in_array;
use function is_string;
use function settype;
use function strtolower;
use function trim;

function topic(string $topic): TopicHelper
{
    return TopicHelper::instance()->with($topic);
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

function extract_last_event_id(ServerRequestInterface $request): ?string
{
    $qs = query_string($request->getUri(), new FlatQueryParser());

    return nullify($request->getHeaderLine('Last-Event-ID'))
        ?? $qs->getParam('Last-Event-ID')
        ?? $qs->getParam('Last-Event-Id')
        ?? $qs->getParam('last-event-id')
        ?? $qs->getParam('LAST-EVENT-ID')
        ?? null;
}
