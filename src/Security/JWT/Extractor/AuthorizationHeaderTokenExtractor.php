<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Freddie\Security\BearerTokenException;
use Psr\Http\Message\ServerRequestInterface;

use function sprintf;
use function strlen;
use function strncasecmp;
use function substr;
use function trim;

final readonly class AuthorizationHeaderTokenExtractor implements PSR7TokenExtractorInterface
{
    public function __construct(
        private string $name = 'Authorization',
        private string $prefix = 'Bearer ',
    ) {
    }

    /**
     * A present header is the only source of the token: a malformed one is rejected rather than ignored, so that
     * another mechanism (e.g. an ambient cookie) cannot silently authenticate the request instead.
     *
     * @throws BearerTokenException
     */
    public function extract(ServerRequestInterface $request): ?string
    {
        if (!$request->hasHeader($this->name)) {
            return null;
        }

        // Authentication schemes are case-insensitive (RFC 9110).
        $authorizationHeader = $request->getHeaderLine($this->name);
        $token = trim(substr($authorizationHeader, strlen($this->prefix)));
        if (0 !== strncasecmp($authorizationHeader, $this->prefix, strlen($this->prefix)) || '' === $token) {
            throw BearerTokenException::invalidRequest(sprintf('Invalid "%s" header.', $this->name));
        }

        return $token;
    }
}
