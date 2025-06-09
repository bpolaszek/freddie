<?php

declare(strict_types=1);

namespace App\Tests;

use Freddie\Hub\Hub;
use Freddie\Hub\HubInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

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
