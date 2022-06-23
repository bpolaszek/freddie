<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Psr\Http\Message\ServerRequestInterface;
use Traversable;

use function iterator_to_array;

final class ChainTokenExtractor implements PSR7TokenExtractorInterface
{
    /**
     * @param iterable<PSR7TokenExtractorInterface> $tokenExtractors
     */
    public function __construct(
        private iterable $tokenExtractors = [
            new CookieTokenExtractor(),
            new AuthorizationHeaderTokenExtractor(),
            new QueryTokenExtractor(),
        ],
    ) {
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
