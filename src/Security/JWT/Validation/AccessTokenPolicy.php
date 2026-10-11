<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Validation;

use Freddie\Matcher\TopicMatcherStore;
use Lcobucci\JWT\Validation\Constraint;
use Psr\Http\Message\ServerRequestInterface;

use function explode;
use function trim;

/**
 * The access token requirements of the protocol, which depend on the request (the audience is the hub's
 * resource identifier). In compatibility mode, any signed token is accepted, as in protocol version 8.
 */
final readonly class AccessTokenPolicy
{
    public function __construct(
        public bool $legacy = false,
        private ?string $issuer = null,
        private ?string $resourceIdentifier = null,
    ) {
    }

    /**
     * @return Constraint[]
     */
    public function constraintsFor(ServerRequestInterface $request): array
    {
        if ($this->legacy) {
            return [];
        }

        $constraints = [new AccessTokenConstraint()];
        if (null !== $this->issuer && '' !== $this->issuer) {
            $constraints[] = new Constraint\IssuedBy($this->issuer);
        }
        $constraints[] = new Constraint\PermittedFor($this->resourceIdentifierFor($request));

        return $constraints;
    }

    /**
     * The configured resource identifier or, by default, the hub URL derived from the request.
     * The derived value relies on request headers: configure the resource identifier in production.
     *
     * @return non-empty-string
     */
    public function resourceIdentifierFor(ServerRequestInterface $request): string
    {
        if (null !== $this->resourceIdentifier && '' !== $this->resourceIdentifier) {
            return $this->resourceIdentifier;
        }

        $uri = $request->getUri();
        $scheme = self::firstForwardedValue($request, 'X-Forwarded-Proto') ?? $uri->getScheme();
        $host = self::firstForwardedValue($request, 'X-Forwarded-Host')
            ?? ('' !== $uri->getAuthority() ? $uri->getAuthority() : $request->getHeaderLine('Host'));

        return ('' !== $scheme ? $scheme : 'http') . '://' . $host . TopicMatcherStore::RESERVED_PATH;
    }

    private static function firstForwardedValue(ServerRequestInterface $request, string $header): ?string
    {
        $value = trim(explode(',', $request->getHeaderLine($header))[0]);

        return '' !== $value ? $value : null;
    }
}
