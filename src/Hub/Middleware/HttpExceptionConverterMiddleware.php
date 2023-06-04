<?php

declare(strict_types=1);

namespace Freddie\Hub\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Http\Message\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class HttpExceptionConverterMiddleware
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        callable $next
    ): ResponseInterface {
        try {
            return $next($request);
        } catch (HttpException $e) {
            $this->logger->error(sprintf('HTTP: %s, %s', $e->getStatusCode(), $e->getMessage()));

            return new Response($e->getStatusCode(), body: $e->getMessage());
        }
    }
}
