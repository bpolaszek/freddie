<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Hub;

use FrameworkX\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Hub
{
    /**
     * @param iterable<HubControllerInterface> $controllers
     */
    public function __construct(
        iterable $controllers = [],
        private App $app = new App(),
    ) {
        foreach ($controllers as $controller) {
            $method = $controller->getMethod();
            $route = $controller->getRoute();
            $this->app->{$method}($route, $this, $controller);
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function run(): void
    {
        $this->app->run();
    }

    public function __invoke(
        ServerRequestInterface $request,
        callable $next
    ): ResponseInterface {
        try {
            return $next($request);
        } catch (HttpException $e) {
            return new Response($e->getStatusCode(), body: $e->getMessage());
        }
    }
}
