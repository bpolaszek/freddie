<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Configuration;

use Freddie\Security\JWT\Configuration\ConfigurationFactory;
use Lcobucci\JWT\Signer;

it('creates a symmetric configuration', function () {
    $build = new ConfigurationFactory();
    $config = $build(
        'HS512',
        'foobar',
    );
    expect($config->signer())->toBeInstanceOf(Signer\Hmac\Sha512::class)
        ->and($config->signingKey()->contents())->toBe('foobar')
        ->and($config->verificationKey()->contents())->toBe('foobar');
});

it('creates an asymmetric configuration', function () {
    $build = new ConfigurationFactory();
    $privateKey = <<<EOF
-----BEGIN ENCRYPTED PRIVATE KEY-----
MIIFHDBOBgkqhkiG9w0BBQ0wQTApBgkqhkiG9w0BBQwwHAQIrvagCSwOEMMCAggA
MAwGCCqGSIb3DQIJBQAwFAYIKoZIhvcNAwcECKb6njQM3B5NBIIEyK/ntLS2LHjV
m2hLxzQ9MJp2YS3dlmF+vG0k4NOXJfA0x8mY+Ho1gccNxsTpEekbf1NSJIA9P9RA
8UD3vYOviRTgdC52FxZX/LClzr7nAdWSDMyfQtI+DT5FuZeJ0L0iQpsueklsvHAR
3WXVf/ZhqgAa0dShDHQycR1Mn5jZ4FCT8InRwUteTDdwbHJsBS8ClFPkiQv8yLEI
l9tPjOEl1EBkRJKMb1OZoYcQX7FobrxK/wQy8WddY47bpsy9lW9UjfQeuv7LpMZ9
qDqP60WJBvROSyYbcT8sAloGIk9bEaXMAYt7KTVYQao4leewKxNpSG/ZzyhztXac
PQCv8z43zj6Rx1+3nfmQV8L5vC69LqwsOj9BZa8K+nENt+WgFHMpi/1PYQNqcL7B
hSCNrAAVpRZm5cbxjdSt6Ya5UIDtd65iuCBqJqbS6+Cb9G8g/GLiDwjAdkDYBerA
gny3aW/ixZqVzgoUk9F1hD1FGyfDjc8goSZtpyK/znOqeEA40hwk1AuyLoS5HyDy
4inuUoo/rF3JZEjZiCu81qBfvA7VTbqkNN4Z87QcLzOsvt6mSuuHeRKqQ9O5h/68
h9mCcD08gIsvKnve+NZ28uChyXK+jQv4paGHeCCe13za8vieMA83+faZTvIrMNVC
VLP/JNnf7wL8SM+p+LbcvumX8XKsYaX4erQjzT2tvx2zhZJVFZ2e0VtSp9aSgmJV
D+WH0I1J2L1RsPxiSvzns5uYe32vfy2YDu0j64gEeFrLDB8qVzZo6xF96IoqUauJ
39l/Nf25o/tXuUCOXS0sJYbYxDNW1rn9fb0vcg2maxMzhi9o35WBe8ZFQrIu35di
UlmbsIbuS8IaXc5v+QUDee01e903BnDvBrnZOO7cYWc8lvuqqowR1nh4Mw/QdbFP
X5XoW7HA+R/d36dEDgiKQerN1kaU3dBXGG75S8OFxptKrmYygzlotyRvHD+DapdZ
3RPYIMEo7rMehVCxPz0fDHWmZRSGkBKOEW4816tdWRjlfBkdgGoWfOFixXMhMACh
QRD+kaXuKabw6ow4viPcyzeGMwxiEC2OOhIeP8SC5W7PHtnoC3+d66WQIvmdGmzQ
78KskrNrQHY3SgRjjze9POjt+/XTzo03CfKKRA7PBhfWoe6TEMkgT91L0p2CGpz1
sINR9v/ZFWmd6cRMtk9US0IjoVpuxIfVg9BBVNQdfCXPgIpilx2jptR6X42QzTJk
M2MZDE5Co0F+7x57XSoLtfNWg1hdWF9IJkLi7T9P1jor6+S0F3v0QhrPfHTm9Aco
SQ8B4/Sour5OWNW+iQaLSXYhAbVU0FBRNUDNkcSouULqXmtuv6YS/HxGp1mEKsWn
Zx65I7iXUSuqjyRi4z63WebGAfN3z15HyHu9ZeXNOP2/jMfr8YYCe6vn4fAnHhMC
v5cW6dg4eO1Jed3+BKkuJXNkOTNjgBnaI/jWaa4kA9Sz1T5qiUcqmGkwGOVIzytp
zncuG9Wmtjlfiw1iMidUnw4OYcgqZ+a9P/5TBN/qeg9MeEZ1BtFKQmDETkfUJCV3
x7vqhPOPum9EUl3afXokpZf4nGvmUWYOcVTuE+3RawSvVpFcmRwq1HiaQ3zDFj9w
NasIdLgO67+P9koCxjTz6w==
-----END ENCRYPTED PRIVATE KEY-----

EOF;

    $publicKey = <<<EOF
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqaPNvooFIxW6GuUc8fPU
I7sel0A2vZE6jkKzGxwG2xCv+7pCGfZH/A9ZIapWKrOx5vgcJpsN8jKiiUgYI+t8
CEhz+qCnQGLJU/j+znjSZW/a85EQZLgqW5sgb7UfG1vVOYKder7yOxwTn/ws72aK
tAewHnvpVet70sZWe4f0V9giIXt6vTregV34+TPirBzchaXoTMKgXXUHmDhCkm4S
HeoeGLc4FJTG/ik5SIPvcFRK984bwZM32sXPQt/dRKva7lt5D9o8Aka3WpcGgR+J
KNa6qdlQTdSpmeggC2+tCaKKz8j0CqKTifQ9gxG8wqqze7FU36+r6bJnBGEeAdad
xwIDAQAB
-----END PUBLIC KEY-----

EOF;

    $passphrase = 'foobar';
    $config = $build(
        'RS512',
        $privateKey,
        $publicKey,
        $passphrase,
    );
    expect($config->signer())->toBeInstanceOf(Signer\Rsa\Sha512::class)
        ->and($config->signingKey()->contents())->toBe($privateKey)
        ->and($config->signingKey()->passphrase())->toBe($passphrase)
        ->and($config->verificationKey()->contents())->toBe($publicKey);
});

it('uses the appropriate signer', function (string $algorithm, string $expectedSignerClass) {
    $build = new ConfigurationFactory();
    $config = $build($algorithm, 'foobar', 'foobar');
    expect($config->signer())->toBeInstanceOf($expectedSignerClass);
})->with([
    ['HS256', Signer\Hmac\Sha256::class],
    ['HS384', Signer\Hmac\Sha384::class],
    ['HS512', Signer\Hmac\Sha512::class],
    ['RS256', Signer\Rsa\Sha256::class],
    ['RS384', Signer\Rsa\Sha384::class],
    ['RS512', Signer\Rsa\Sha512::class],
    ['ES256', Signer\Ecdsa\Sha256::class],
    ['ES384', Signer\Ecdsa\Sha384::class],
    ['ES512', Signer\Ecdsa\Sha512::class],
]);
