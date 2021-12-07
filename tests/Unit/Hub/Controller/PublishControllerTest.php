<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Controller;

use Freddie\Hub\Controller\PublishController;
use Freddie\Hub\Hub;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Ulid;

it('publishes updates to the hub', function (
    string $payload,
    array $allowedTopics,
    ResponseInterface $expectedResponse,
    ?Update $expectedUpdate
) {
    $JWTEncoder = Auth::getJWTEncoder();
    $transport = new PHPTransport(size: 1);
    $controller = new PublishController(
        $transport,
        new ChainTokenExtractor(),
        $JWTEncoder,
    );
    $hub = new Hub([$controller]);
    $refl = new ReflectionClass($transport);
    $updates = $refl->getProperty('updates');
    $updates->setAccessible(true);

    // Given
    $jwt = $JWTEncoder->encode(['mercure' => ['publish' => $allowedTopics]]);
    $request = new ServerRequest(
        'POST',
        '/.well-known/mercure',
        [
            'Authorization' => "Bearer $jwt",
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        body: $payload,
    );

    // When
    $response = $hub($request, $controller);
    $update = $updates->getValue($transport)[0] ?? null;

    // Then
    expect($response->getStatusCode())->toBe($expectedResponse->getStatusCode());
    expect((string) $response->getBody())->toBe((string) $expectedResponse->getBody());
    expect($update)->toEqual($expectedUpdate);
})->with(function () {
    $id = Ulid::generate();
    yield [
        'payload' => 'topic=/foo&topic=/bar&data=foobar&event=alert&retry=2&private=true&id=' . $id,
        'allowedTopics' => ['*'],
        'expectedResponse' => new Response(201, body: $id),
        'expectedUpdate' => new Update(['/foo', '/bar'], new Message($id, 'foobar', true, 'alert', 2)),
    ];
    yield [
        'payload' => 'topic=/foo&topic=/bar&data=foobar&event=alert&retry=2&id=' . $id,
        'allowedTopics' => [],
        'expectedResponse' => new Response(201, body: $id),
        'expectedUpdate' => new Update(['/foo', '/bar'], new Message($id, 'foobar', false, 'alert', 2)),
    ];
    yield [
        'payload' => 'topic=/foo&topic=/bar&data=foobar&event=alert&retry=2&private=true&id=' . $id,
        'allowedTopics' => [],
        'expectedResponse' => new Response(403, body: 'Your rights are not sufficient to publish this update.'),
        'expectedUpdate' => null,
    ];
});

it('complains when no jwt is provided', function () {
    $controller = new PublishController(
        new PHPTransport(),
        new ChainTokenExtractor(),
        Auth::getJWTEncoder(),
    );

    // Given
    $request = new ServerRequest(
        'POST',
        '/.well-known/mercure',
        ['Content-Type' => 'application/x-www-form-urlencoded'],
        'topic=/foo&data=bar'
    );

    // When
    $controller($request);
})->throws(
    AccessDeniedHttpException::class,
    'You must be authenticated to publish on this hub.'
);

it('complains when JWT is invalid', function () {
    $JWTEncoder = Auth::getJWTEncoder();
    $controller = new PublishController(
        new PHPTransport(),
        new ChainTokenExtractor(),
        $JWTEncoder,
    );

    // Given
    $jwt = $JWTEncoder->encode(['mercure' => ['publish' => ['*']]]) . 'foo';
    $request = new ServerRequest(
        'POST',
        '/.well-known/mercure',
        [
            'Authorization' => "Bearer $jwt",
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'topic=/foo&data=bar'
    );

    // When
    $controller($request);
})->throws(
    AccessDeniedHttpException::class,
    'Invalid JWT Token'
);

it('complains if JWT does not contain a mercure.publish claim', function () {
    $JWTEncoder = Auth::getJWTEncoder();
    $controller = new PublishController(
        new PHPTransport(),
        new ChainTokenExtractor(),
        $JWTEncoder,
    );

    // Given
    $jwt = $JWTEncoder->encode([]);
    $request = new ServerRequest(
        'POST',
        '/.well-known/mercure',
        [
            'Authorization' => "Bearer $jwt",
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'topic=/foo&data=bar'
    );

    // When
    $controller($request);
})->throws(
    AccessDeniedHttpException::class,
    'Missing mercure.publish claim.'
);

it('yells when no topic is provided', function () {
    $controller = new PublishController(
        new PHPTransport(),
        new ChainTokenExtractor(),
        Auth::getJWTEncoder(),
    );

    // Given
    $request = new ServerRequest(
        'POST',
        '/.well-known/mercure',
        ['Content-Type' => 'application/x-www-form-urlencoded'],
        'data=bar'
    );

    // When
    $controller($request);

    // Then
})->throws(
    BadRequestHttpException::class,
    'Missing topic parameter.'
);

it('yells when update cannot be published', function () {
    $JWTEncoder = Auth::getJWTEncoder();
    $controller = new PublishController(
        new PHPTransport(),
        new ChainTokenExtractor(),
        $JWTEncoder,
    );

    // Given
    $jwt = $JWTEncoder->encode(['mercure' => ['publish' => ['/bar']]]);
    $request = new ServerRequest(
        'POST',
        '/.well-known/mercure',
        [
            'Authorization' => "Bearer $jwt",
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'topic=/foo&data=bar&private=yes'
    );

    // When
    $controller($request);

    // Then
})->throws(
    AccessDeniedHttpException::class,
    'Your rights are not sufficient to publish this update.'
);
