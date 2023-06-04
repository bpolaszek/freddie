<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Middleware;

use FrameworkX\App;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use function Freddie\Tests\handle;

it('converts HttpExceptions to Responses', function () {
    $logger = \Mockery::mock(LoggerInterface::class);
    $logger
        ->shouldReceive('error')
        ->with('HTTP: 403, Nope.');

    $expectedResponse = new Response(204);
    $app = new App(new HttpExceptionConverterMiddleware($logger), fn () => $expectedResponse);
    $request = new ServerRequest('POST', './well-known/mercure');

    // When
    $response = handle($app, $request);

    // Then
    expect($response)->toBe($expectedResponse);

    // Given
    $app = new App(
        new HttpExceptionConverterMiddleware($logger),
        fn () => throw new AccessDeniedHttpException('Nope.')
    );

    // When
    $response = handle($app, $request);

    // Then
    expect($response)->toBeInstanceOf(ResponseInterface::class);
    expect($response->getStatusCode())->toBe(403);
    expect((string) $response->getBody())->toBe('Nope.');
});
