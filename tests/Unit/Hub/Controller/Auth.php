<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Controller;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\LcobucciJWTEncoder;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWSProvider\LcobucciJWSProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\KeyLoader\RawKeyLoader;

use function file_get_contents;

/**
 * @internal
 * @deprecated
 */
final class Auth
{
    /**
     * @deprecated
     */
    public static function getJWTEncoder(int $ttl = 3600, int $clockSkew = 0): JWTEncoderInterface
    {
        static $encoder;
        return $encoder ??= new LcobucciJWTEncoder(
            new LcobucciJWSProvider(
                new RawKeyLoader(
                    file_get_contents(__DIR__ . '/../../../../tests/config/jwt/private.pem'),
                    file_get_contents(__DIR__ . '/../../../../tests/config/jwt/public.pem'),
                    'qQspYKE3YUbXNeCYW6wEf45tuerqzB2t',
                ),
                'openssl',
                'RS256',
                $ttl,
                $clockSkew,
            ),
        );
    }
}
