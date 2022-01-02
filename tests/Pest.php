<?php

declare(strict_types=1);

namespace Freddie\Tests;

use _PHPStan_c862bb974\OndraM\CiDetector\Ci\AbstractCi;
use FrameworkX\App;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Security\JWT\Configuration\ConfigurationFactory;
use Lcobucci\JWT\Configuration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

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

function create_jwt(array $claims): string {
    $builder = jwt_config()->builder();
    foreach ($claims as $key => $value) {
        $builder = $builder->withClaim($key, $value);
    }

    return $builder->getToken(jwt_config()->signer(), jwt_config()->signingKey())->toString();
}
