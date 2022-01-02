<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Configuration;

use Freddie\Security\JWT\Configuration\VerificationKeyFactory;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

it('returns the verification key', function () {
    $factory = new VerificationKeyFactory(
        Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText('foo'),
            InMemory::plainText('bar'),
        )
    );
    $key = $factory();
    expect($key->contents())->toBe('bar');
});
