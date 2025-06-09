<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Symfony\Component\HttpFoundation\Request;
use Traversable;

use function iterator_to_array;

final class ChainTokenExtractor implements TokenExtractorInterface
{
    /**
     * @param iterable<TokenExtractorInterface> $tokenExtractors
     */
    public function __construct(
        private iterable $tokenExtractors = [
            new QueryTokenExtractor(),
            new CookieTokenExtractor(),
            new AuthorizationHeaderTokenExtractor(),
        ],
    ) {
    }

    public function extract(Request $request): ?string
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
