<?php

namespace Freddie\Hub\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final readonly class CorsMiddleware
{
    public function __construct(
        private ?string $corsOrigin = '*',
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        callable $next
    ): ResponseInterface {
        $response = $next($request);

        $corsOrigin = $this->corsOrigin ?? $this->getOrigin($request);

        $response = $response->withAddedHeader('Access-Control-Allow-Origin', $corsOrigin)
            ->withAddedHeader('Access-Control-Allow-Headers', '*')
            ->withAddedHeader('Access-Control-Allow-Methods', '*')
            ->withAddedHeader('Access-Control-Allow-Credentials', 'true')
            ->withAddedHeader('Access-Control-Expose-Headers', 'Mercure-Last-Event-ID, Link, WWW-Authenticate');

        // Preflight requests are not routed: answer them successfully, but keep the status of the others.
        return 'OPTIONS' === $request->getMethod() ? $response->withStatus(Response::STATUS_OK) : $response;
    }

    private function getOrigin(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine('Origin') ?: 'null';
    }
}
