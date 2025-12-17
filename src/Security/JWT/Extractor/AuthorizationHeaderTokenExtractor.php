<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Symfony\Component\HttpFoundation\Request;

use function strlen;
use function str_starts_with;
use function substr;

final readonly class AuthorizationHeaderTokenExtractor implements TokenExtractorInterface
{
    public function __construct(
        private string $name = 'Authorization',
        private string $prefix = 'Bearer ',
    ) {
    }

    public function extract(Request $request): ?string
    {
        if (!$request->headers->has($this->name)) {
            return null;
        }

        $authorizationHeader = $request->headers->get($this->name);
        if (!str_starts_with($authorizationHeader, $this->prefix)) {
            return null;
        }

        $token = substr($authorizationHeader, strlen($this->prefix));

        return strlen($token) < 41 ? null : $token;
    }
}
