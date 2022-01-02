<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Configuration;

use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use function base64_decode;
use function is_readable;

final class ConfigurationFactory
{
    public function __construct(
        private Signer\Ecdsa\SignatureConverter $signatureConverter = new Signer\Ecdsa\MultibyteStringConverter(),
    ) {
    }

    public function __invoke(
        string $algorithm,
        string $secretKey,
        ?string $publicKey = null,
        ?string $passphrase = null,
    ): Configuration {
        return match ($algorithm) {
            'HS256', 'HS384', 'HS512' => $this->createSymmetricConfiguration($algorithm, $secretKey),
            default => $this->createAsymmetricConfiguration(
                $algorithm,
                $secretKey,
                $publicKey ?? throw new InvalidArgumentException('Missing public key.'),
                $passphrase ?? '',
            )
        };
    }

    private function createSymmetricConfiguration(string $algorithm, string $secretKey): Configuration
    {
        return Configuration::forSymmetricSigner(
            $this->getSigner($algorithm),
            $this->getKey($secretKey),
        );
    }

    private function createAsymmetricConfiguration(
        string $algorithm,
        string $secretKey,
        string $publicKey,
        string $passphrase,
    ): Configuration {
        return Configuration::forAsymmetricSigner(
            $this->getSigner($algorithm),
            $this->getKey($secretKey, $passphrase),
            $this->getKey($publicKey),
        );
    }

    private function getSigner(string $algorithm): Signer
    {
        return match ($algorithm) {
            'HS256' => new Signer\Hmac\Sha256(),
            'HS384' => new Signer\Hmac\Sha384(),
            'HS512' => new Signer\Hmac\Sha512(),
            'RS256' => new Signer\Rsa\Sha256(),
            'RS384' => new Signer\Rsa\Sha384(),
            'RS512' => new Signer\Rsa\Sha512(),
            'ES256' => new Signer\Ecdsa\Sha256($this->signatureConverter),
            'ES384' => new Signer\Ecdsa\Sha384($this->signatureConverter),
            'ES512' => new Signer\Ecdsa\Sha512($this->signatureConverter),
        };
    }

    private function getKey(string $key, ?string $passphrase = null): Signer\Key
    {
        return match (true) {
            is_readable($key) => Signer\Key\InMemory::file($key, $passphrase ?? ''),
            default => Signer\Key\InMemory::plainText($key, $passphrase ?? '')
        };
    }
}
