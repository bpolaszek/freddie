<?php

declare(strict_types=1);

namespace Freddie\Helper;

use BenTools\QueryString\Parser\QueryStringParserInterface;

use function explode;
use function is_array;
use function str_contains;
use function urldecode;

final readonly class FlatQueryParser implements QueryStringParserInterface
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $queryString): array
    {
        $params = [];
        $pairs = explode('&', $queryString);
        foreach ($pairs as $pair) {
            if (!isset($params[$pair]) && false === str_contains($pair, '=')) {
                $key = urldecode($pair);
                $params[$key] = null;
                continue;
            }
            [$key, $value] = explode('=', $pair);
            $key = urldecode($key);
            $value = urldecode($value);
            if (!isset($params[$key])) {
                $params[$key] = $value;
            } elseif (!is_array($params[$key])) {
                $params[$key] = [$params[$key], $value];
            } else {
                $params[$key][] = $value;
            }
        }

        return $params;
    }
}
