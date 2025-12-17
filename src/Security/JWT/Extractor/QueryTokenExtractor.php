<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Psr\Http\Message\ServerRequestInterface;

use function BenTools\QueryString\query_string;
use function is_string;
use function strlen;

final readonly class QueryTokenExtractor implements PSR7TokenExtractorInterface
{
    public function __construct(
        private string $name = 'authorization',
    ) {
    }

    public function extract(ServerRequestInterface $request): ?string
    {
        $qs = query_string($request->getUri());
        $authorizationQuery = $qs->getParam($this->name);
        if (!is_string($authorizationQuery) || strlen($authorizationQuery) < 41) {
            return null;
        }

        return $authorizationQuery;
    }
}
