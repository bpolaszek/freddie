<?php

namespace Freddie\Tests;

use Freddie\Message\Message;
use Freddie\Security\JWT\Configuration\ConfigurationFactory;
use Lcobucci\JWT\Configuration;
use Symfony\Component\HttpFoundation\Request;

use function array_filter;
use function BenTools\QueryString\query_string;
use function bin2hex;

/**
 * @param array<string, mixed> $headers
 * @param array<string, mixed> $cookies
 * @internal
 */
function createSfRequest(
    string $method,
    string $uri,
    array $headers = [],
    array $cookies = [],
    ?string $content = null,
): Request {
    $request = Request::create($uri, $method, content: $content);
    $request->headers->replace($headers);
    $request->cookies->replace($cookies);

    return $request;
}

function defaultJwtConfiguration(): Configuration
{
    static $factory, $config;
    $factory ??= new ConfigurationFactory();
    $config ??= $factory('HS256', bin2hex(random_bytes(16)));

    return $config;
}

/**
 * @param array<string, mixed> $claims
 */
function createJWT(array $claims = [], ?Configuration $configuration = null): string
{
    $configuration ??= defaultJwtConfiguration();
    $builder = $configuration->builder();
    foreach ($claims as $key => $value) {
        $builder = $builder->withClaim($key, $value);
    }


    /** @var \Lcobucci\JWT\Signer\Key $verificationKey */
    $verificationKey = $configuration->verificationKey();
    $signer = $configuration->signer();

    return $builder->getToken($signer, $verificationKey)->toString();
}

function stringifyMessage(Message $message): string
{
    $normalized = array_filter(
        [
            'id' => (string) $message->id,
            'data' => $message->data,
            'private' => $message->private,
            'event' => $message->event,
            'retry' => $message->retry,
        ],
        fn (mixed $value) => null !== $value
    );

        return (string) query_string($normalized);
}
