<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Extractor;

use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function implode;
use function strlen;

final readonly class CookieTokenExtractor implements TokenExtractorInterface
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

    public function extract(Request $request): ?string
    {
        $token = implode(
            '.',
            array_map(
                fn (string $name) => $request->cookies->get($name) ?? '',
                $this->cookieNames,
            ),
        );

        if (strlen($token) < 41) {
            return null;
        }

        return $token;
    }
}
