<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Validation;

use Lcobucci\JWT\Token;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint;
use Lcobucci\JWT\Validation\ConstraintViolation;

use function in_array;
use function is_string;
use function strtolower;

/**
 * Requires an RFC 9068 access token: `typ` header set to `at+jwt`, `iss` and `exp` claims present.
 * Their values are checked by dedicated constraints (`IssuedBy`, `LooseValidAt`).
 */
final readonly class AccessTokenConstraint implements Constraint
{
    public const array ACCESS_TOKEN_TYPES = ['at+jwt', 'application/at+jwt'];

    public function assert(Token $token): void
    {
        $type = $token->headers()->get('typ');
        if (!is_string($type) || !in_array(strtolower($type), self::ACCESS_TOKEN_TYPES, true)) {
            throw ConstraintViolation::error('The token is not an access token (typ must be "at+jwt")', $this);
        }

        foreach ([RegisteredClaims::ISSUER, RegisteredClaims::EXPIRATION_TIME] as $claim) {
            if (!$token instanceof UnencryptedToken || !$token->claims()->has($claim)) {
                throw ConstraintViolation::error('The token does not have the "' . $claim . '" claim', $this);
            }
        }
    }
}
