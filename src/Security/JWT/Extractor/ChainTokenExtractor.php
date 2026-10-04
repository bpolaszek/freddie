<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Psr\Http\Message\ServerRequestInterface;
use Traversable;

use function iterator_to_array;

final class ChainTokenExtractor implements PSR7TokenExtractorInterface
{
    public const string COOKIE_NAME = '__Secure-mercure_access_token';
    public const string LEGACY_COOKIE_NAME = 'mercureAuthorization';

    /**
     * @param iterable<PSR7TokenExtractorInterface> $tokenExtractors
     */
    public function __construct(
        private iterable $tokenExtractors = [
            new AuthorizationHeaderTokenExtractor(),
            new CookieTokenExtractor(self::COOKIE_NAME),
        ],
    ) {
    }

    /**
     * The `Authorization` header takes precedence over the cookie. In compatibility mode, the legacy cookie
     * and the `authorization` query parameter (forbidden by RFC 9700 since tokens leak through URLs) are
     * accepted too.
     */
    public static function create(bool $legacy = false, string $cookieName = self::COOKIE_NAME): self
    {
        $extractors = [
            new AuthorizationHeaderTokenExtractor(),
            new CookieTokenExtractor($cookieName),
        ];

        if ($legacy) {
            $extractors[] = new CookieTokenExtractor(self::LEGACY_COOKIE_NAME);
            $extractors[] = new QueryTokenExtractor();
        }

        return new self($extractors);
    }

    public function extract(ServerRequestInterface $request): ?string
    {
        if ($this->tokenExtractors instanceof Traversable) {
            $this->tokenExtractors = iterator_to_array($this->tokenExtractors); // @codeCoverageIgnore
        }

        foreach ($this->tokenExtractors as $extractor) {
            if (null !== ($token = $extractor->extract($request))) {
                return $token;
            }
        }

        return null;
    }
}
