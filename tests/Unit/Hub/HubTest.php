<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub;

use Freddie\Hub\Hub;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

it('converts HttpExceptions to Responses', function () {
    // Given
    $hub = new Hub();
    $request = new ServerRequest('POST', './well-known/mercure');

    // When
    $response = new Response(204);
    $next = fn() => $response;

    // Then
    expect($hub($request, $next))->toBe($response);

    // When
    $next = fn() => throw new AccessDeniedHttpException('Nope.');
    $response = $hub($request, $next);

    // Then
    expect($response)->toBeInstanceOf(ResponseInterface::class);
    expect($response->getStatusCode())->toBe(403);
    expect((string) $response->getBody())->toBe('Nope.');
});
