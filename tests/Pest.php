<?php

declare(strict_types=1);

namespace Freddie\Tests;

use FrameworkX\App;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Tests\Unit\Hub\Controller\Auth;
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
    $hydrater ??= new TokenExtractorMiddleware(Auth::getJWTEncoder());
    $class ??= new ReflectionClass($hydrater);
    $method ??= $class->getMethod('withToken');
    $method->setAccessible(true);

    return $method->invoke($hydrater, $request, $token);
}
