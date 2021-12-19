<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Middleware;

use FrameworkX\App;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Tests\Unit\Hub\Controller\Auth;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use RingCentral\Psr7\ServerRequest;

use function Freddie\Tests\handle;
use function json_encode;

it('extracts the token and stores it in an attribute', function () {
    $JWTEncoder = Auth::getJWTEncoder();
    $jwt = $JWTEncoder->encode(['mercure' => ['publish' => ['*']]]);
    $app = new App(
        new TokenExtractorMiddleware($JWTEncoder),
        fn (ServerRequestInterface $request) => new Response(201, body: json_encode($request->getAttribute('token')))
    );

    // Given
    $request = new ServerRequest('GET', '/', [
        'Authorization' => "Bearer $jwt",
    ]);

    // When
    $response = handle($app, $request);

    // Then
    expect((string) $response->getBody())->toBe(json_encode($JWTEncoder->decode($jwt)));
});

it('does nothing when JWT is not provided', function () {
    $JWTEncoder = Auth::getJWTEncoder();
    $app = new App(
        new TokenExtractorMiddleware($JWTEncoder),
        fn (ServerRequestInterface $request) => new Response(201, body: (string) $request->getAttribute('token'))
    );

    // Given
    $request = new ServerRequest('GET', '/');

    // When
    $response = handle($app, $request);

    // Then
    expect((string) $response->getBody())->toBe('');
});
