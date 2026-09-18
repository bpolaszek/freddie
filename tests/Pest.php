<?php

declare(strict_types=1);

namespace Freddie\Tests;

use FrameworkX\App;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Security\JWT\Configuration\ConfigurationFactory;
use Lcobucci\JWT\Configuration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

function handle(App $app, ServerRequestInterface $request): ResponseInterface
{
    static $class, $method;
    $class ??= new ReflectionClass($app);
    $method ??= $class->getMethod('handleRequest');
    $method->setAccessible(true);

    return $method->invoke($app, $request);
}

function with_token(ServerRequestInterface $request, string $token): ServerRequestInterface
{
    static $hydrater, $class, $method;
    $hydrater ??= new TokenExtractorMiddleware();
    $class ??= new ReflectionClass($hydrater);
    $method ??= $class->getMethod('withToken');
    $method->setAccessible(true);

    return $method->invoke($hydrater, $request, $token);
}

function jwt_config(): Configuration
{
    static $factory, $config;
    $factory ??= new ConfigurationFactory();

    return $config ??= $factory(
        $_SERVER['JWT_ALGORITHM'],
        \file_get_contents(\strtr($_SERVER['JWT_SECRET_KEY'], ['%kernel.project_dir%' => \dirname(__DIR__)])),
        \file_get_contents(\strtr($_SERVER['JWT_PUBLIC_KEY'], ['%kernel.project_dir%' => \dirname(__DIR__)])),
        $_SERVER['JWT_PASSPHRASE'],
    );
}

/**
 * The Symfony-based serializer previously used for the Redis transport,
 * kept as a dev dependency to assert wire-format compatibility.
 */
function legacy_redis_serializer(): Serializer
{
    static $serializer;

    return $serializer ??= new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
}

function create_jwt(array $claims): string
{
    $builder = jwt_config()->builder();
    foreach ($claims as $key => $value) {
        $builder = $builder->withClaim($key, $value);
    }

    return $builder->getToken(jwt_config()->signer(), jwt_config()->signingKey())->toString();
}
