<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Middleware;

use FrameworkX\App;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Tests\Unit\Hub\Controller\Auth;
use Lcobucci\JWT\Token;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use RingCentral\Psr7\ServerRequest;

use function Freddie\Tests\create_jwt;
use function Freddie\Tests\handle;
use function Freddie\Tests\jwt_config;
use function json_encode;

it('extracts the token and stores it in an attribute', function () {
    $jwt = create_jwt(['mercure' => ['publish' => ['*']]]);
    $token = null;
    $app = new App(
        new TokenExtractorMiddleware(jwt_config()->parser(), jwt_config()->validator()),
        function (ServerRequestInterface $request) use (&$token) {
            $token = $request->getAttribute('token');

            return new Response(204);
        }
    );

    // Given
    $request = new ServerRequest('GET', '/', [
        'Authorization' => "Bearer $jwt",
    ]);

    // When
    handle($app, $request);

    // Then
    expect($token)->toBeInstanceOf(Token::class);
});

it('does nothing when JWT is not provided', function () {
    $token = null;
    $app = new App(
        new TokenExtractorMiddleware(jwt_config()->parser(), jwt_config()->validator()),
        function (ServerRequestInterface $request) use (&$token) {
            $token = $request->getAttribute('token') ?? 'No token provided';

            return new Response(204);
        }
    );

    // Given
    $request = new ServerRequest('GET', '/');

    // When
    handle($app, $request);

    // Then
    expect($token)->toBe('No token provided');
});
