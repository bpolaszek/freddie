<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Psr\Http\Message\ServerRequestInterface;

use function strlen;
use function str_starts_with;
use function substr;

final class AuthorizationHeaderTokenExtractor implements PSR7TokenExtractorInterface
{
    public function __construct(
        private string $name = 'Authorization',
        private string $prefix = 'Bearer ',
    ) {
    }

    public function extract(ServerRequestInterface $request): ?string
    {
        if (!$request->hasHeader($this->name)) {
            return null;
        }

        $authorizationHeader = $request->getHeaderLine($this->name);
        if (!str_starts_with($authorizationHeader, $this->prefix)) {
            return null;
        }

        $token = substr($authorizationHeader, strlen($this->prefix));

        return strlen($token) < 41 ? null : $token;
    }
}
