<?php

declare(strict_types=1);

namespace App\Tests;

use BenTools\ReflectionPlus\Reflection;
use Freddie\Hub\Hub;
use Freddie\Hub\HubInterface;
use Freddie\Transport\Redis\RedisTransport;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

use function assert;
use function BenTools\Pest\Symfony\inject;
use function expect;

it('exposes services', function () {
    $hub = inject(HubInterface::class);
    expect($hub)->toBeInstanceOf(Hub::class);
});

it('exposes routes', function () {
    $router = inject(RouterInterface::class);
    expect($router->getRouteCollection()->get('freddie.subscribe'))->toBeInstanceOf(Route::class)
        ->and($router->getRouteCollection()->get('freddie.publish'))->toBeInstanceOf(Route::class)
        ->and($router->getRouteCollection()->get('freddie.list_subscriptions'))->toBeInstanceOf(Route::class)
        ->and($router->getRouteCollection()->get('freddie.get_subscription'))->toBeInstanceOf(Route::class);
});

it('parses the transport DSN and hydrates options', function () {
    assert(!empty($_SERVER['REDIS_SIZE'])); // see .env.test
    assert(!empty($_SERVER['REDIS_STREAM'])); // see .env.test

    $transport = inject(RedisTransport::class);
    $resolvedOptions = Reflection::property($transport, 'options')->getValue($transport);
    expect($resolvedOptions['size'])->toEqual($_SERVER['REDIS_SIZE'])
        ->and($resolvedOptions['stream'])->toEqual($_SERVER['REDIS_STREAM']);
    ;
});
