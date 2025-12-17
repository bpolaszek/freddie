<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Psr\Http\Message\ServerRequestInterface;

use function array_map;
use function implode;
use function is_string;
use function strlen;

final readonly class CookieTokenExtractor implements PSR7TokenExtractorInterface
{
    /**
     * @var string[]
     */
    private array $cookieNames;

    /**
     * @param string|string[] $cookieName
     */
    public function __construct(
        string|array $cookieName = 'mercureAuthorization',
    ) {
        $this->cookieNames = (array) $cookieName;
    }

    public function extract(ServerRequestInterface $request): ?string
    {
        $token = implode(
            '.',
            array_map(
                fn (string $name) => $request->getCookieParams()[$name] ?? '',
                $this->cookieNames,
            ),
        );

        if (!is_string($token) || strlen($token) < 41) {
            return null;
        }

        return $token;
    }
}
