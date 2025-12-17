<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Configuration;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;

/**
 * @codeCoverageIgnore
 */
final readonly class SignerFactory
{
    public function __construct(
        private Configuration $configuration,
    ) {
    }

    public function __invoke(): Signer
    {
        return $this->configuration->signer();
    }
}
