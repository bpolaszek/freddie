<?php

declare(strict_types=1);

namespace Freddie\Hub;

use FrameworkX\App;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;

final class Hub
{
    /**
     * @codeCoverageIgnore
     * @param iterable<HubControllerInterface> $controllers
     */
    public function __construct(
        private App $app = new App(new HttpExceptionConverterMiddleware()),
        iterable $controllers = [],
    ) {
        foreach ($controllers as $controller) {
            $method = $controller->getMethod();
            $route = $controller->getRoute();
            $this->app->{$method}($route, $controller);
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function run(): void
    {
        $this->app->run();
    }
}
