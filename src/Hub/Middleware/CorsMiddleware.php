<?php

namespace Freddie\Hub\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class CorsMiddleware
{
    public function __construct(
        private ?string $corsOrigin
    )
    {
        if (!$this->corsOrigin) {
            $this->corsOrigin = 'null';
        }
    }

    public function __invoke(
        ServerRequestInterface $request,
        callable               $next
    ): ResponseInterface
    {
        $response = $next($request);

        return $response->withAddedHeader('Access-Control-Allow-Origin', $this->corsOrigin)
            ->withAddedHeader('Access-Control-Allow-Headers', '*')
            ->withAddedHeader('Access-Control-Allow-Methods', '*')
            ->withStatus(Response::STATUS_OK);
    }
}