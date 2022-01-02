<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Configuration;

use Freddie\Security\JWT\Configuration\ConfigurationFactory;
use Lcobucci\JWT\Signer;

use function dirname;
use function file_get_contents;

it('creates a symmetric configuration', function () {
    $build = new ConfigurationFactory();
    $config = $build(
        'HS512',
        'foobar',
    );
    expect($config->signer())->toBeInstanceOf(Signer\Hmac\Sha512::class);
    expect($config->signingKey()->contents())->toBe('foobar');
    expect($config->verificationKey()->contents())->toBe('foobar');
});

it('creates an asymmetric configuration', function () {
    $build = new ConfigurationFactory();
    $privateKey = file_get_contents(dirname(__DIR__, 4) . '/config/jwt/private.pem');
    $publicKey = dirname(__DIR__, 4) . '/config/jwt/public.pem';
    $config = $build(
        'RS512',
        $privateKey,
        file_get_contents($publicKey),
        'foobar',
    );
    expect($config->signer())->toBeInstanceOf(Signer\Rsa\Sha512::class);
    expect($config->signingKey()->contents())->toBe($privateKey);
    expect($config->signingKey()->passphrase())->toBe('foobar');
    expect($config->verificationKey()->contents())->toBe(file_get_contents($publicKey));
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
