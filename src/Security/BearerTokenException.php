<?php

declare(strict_types=1);

namespace Freddie\Security;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An authentication / authorization failure, reported with a `WWW-Authenticate: Bearer` challenge (RFC 6750).
 */
final class BearerTokenException extends HttpException
{
    public const string INVALID_REQUEST = 'invalid_request';
    public const string INVALID_TOKEN = 'invalid_token';
    public const string INSUFFICIENT_SCOPE = 'insufficient_scope';

    private function __construct(int $statusCode, string $message, public readonly ?string $error)
    {
        $challenge = null === $error ? 'Bearer' : 'Bearer error="' . $error . '"';
        parent::__construct($statusCode, $message, headers: ['WWW-Authenticate' => $challenge]);
    }

    /**
     * No token was provided, but one is required.
     */
    public static function missingToken(string $message): self
    {
        return new self(401, $message, null);
    }

    public static function invalidToken(string $message): self
    {
        return new self(401, $message, self::INVALID_TOKEN);
    }

    public static function insufficientScope(string $message): self
    {
        return new self(403, $message, self::INSUFFICIENT_SCOPE);
    }
}
