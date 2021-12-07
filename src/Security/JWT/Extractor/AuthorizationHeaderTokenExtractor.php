<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Psr\Http\Message\ServerRequestInterface;

final class AuthorizationHeaderTokenExtractor implements PSR7TokenExtractorInterface
{
    private string $name = 'Authorization';
    private string $prefix = 'Bearer';

    public function extract(ServerRequestInterface $request): ?string
    {
        if (!$request->hasHeader($this->name)) {
            return null;
        }

        $authorizationHeader = $request->getHeaderLine($this->name);
        $headerParts = explode(' ', $authorizationHeader);

        if (!(2 === count($headerParts) && 0 === strcasecmp($headerParts[0], $this->prefix))) {
            return null;
        }

        return $headerParts[1];
    }
}
