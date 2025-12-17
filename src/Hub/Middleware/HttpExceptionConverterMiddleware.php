<?php

declare(strict_types=1);

namespace Freddie\Hub\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class HttpExceptionConverterMiddleware
{
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
