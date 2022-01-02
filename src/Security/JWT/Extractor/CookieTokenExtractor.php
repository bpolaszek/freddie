<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Psr\Http\Message\ServerRequestInterface;

final class CookieTokenExtractor implements PSR7TokenExtractorInterface
{
    public function __construct(
        private string $name = 'mercureAuthorization',
    ) {
    }

    public function extract(ServerRequestInterface $request): ?string
    {
        return $request->getCookieParams()[$this->name] ?? null;
    }
}
