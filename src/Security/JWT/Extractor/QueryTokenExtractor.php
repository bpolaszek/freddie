<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Symfony\Component\HttpFoundation\Request;

use function strlen;

final readonly class QueryTokenExtractor implements TokenExtractorInterface
{
    public function __construct(
        private string $name = 'authorization',
    ) {
    }

    public function extract(Request $request): ?string
    {
        $authorizationQuery = $request->query->getString($this->name);
        if (strlen($authorizationQuery) < 41) {
            return null;
        }

        return $authorizationQuery;
    }
}
