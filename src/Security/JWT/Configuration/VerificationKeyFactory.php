<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Configuration;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key;

final readonly class VerificationKeyFactory
{
    public function __construct(
        private Configuration $configuration,
    ) {
    }

    public function __invoke(): Key
    {
        return $this->configuration->verificationKey();
    }
}
