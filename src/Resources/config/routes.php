<?php

declare(strict_types=1);

namespace Freddie\Resources\config;

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;

return function (RoutingConfigurator $routes): void {
    $routes->import(dirname(__DIR__, 2) . '/Controller', 'attribute');
};
