<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Middleware;

use FrameworkX\App;
use Freddie\Hub\Middleware\CorsMiddleware;
use React\Http\Message\Response;

use function expect;
use function Freddie\Tests\handle;
use function it;

it('decorate response with Cross-Origin Resource Sharing mechanism', function () {
    // Given
    $corsOrigins = 'http://testdomain.local';
    $expectedResponse = new Response(200);
    $app = new App(new CorsMiddleware($corsOrigins), fn () => $expectedResponse);
    $request = new \React\Http\Message\ServerRequest('POST', './well-known/mercure');

    // When
    $response = handle($app, $request);

    // Then
    expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe($corsOrigins);
    expect($response->getHeaderLine('Access-Control-Allow-Methods'))->toBe('*');
    expect($response->getHeaderLine('Access-Control-Allow-Headers'))->toBe('*');
    expect($response->getHeaderLine('Access-Control-Allow-Credentials'))->toBe('true');
});

it('decorate response with Cross-Origin Resource Sharing mechanism with default corsOrigin setup', function () {
    // Given
    $corsOrigins = null;
    $expectedResponse = new Response(400);
    $app = new App(new CorsMiddleware($corsOrigins), fn () => $expectedResponse);
    $request = new \React\Http\Message\ServerRequest('POST', './well-known/mercure');

    // When
    $response = handle($app, $request);

    // Then
    expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('null');
    expect($response->getHeaderLine('Access-Control-Allow-Methods'))->toBe('*');
    expect($response->getHeaderLine('Access-Control-Allow-Headers'))->toBe('*');
    expect($response->getHeaderLine('Access-Control-Allow-Credentials'))->toBe('true');
});
