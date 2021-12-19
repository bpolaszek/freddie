<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Controller;

use FrameworkX\App;
use Freddie\Hub\Controller\PublishController;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Ulid;

use function Freddie\Tests\handle;
use function Freddie\Tests\with_token;

it('publishes updates to the hub', function (
    string $payload,
    array $allowedTopics,
    ResponseInterface $expectedResponse,
    ?Update $expectedUpdate
) {
    $JWTEncoder = Auth::getJWTEncoder();
    $transport = new PHPTransport(size: 1);
    $controller = new PublishController($transport);
    $app = new App(
        new TokenExtractorMiddleware($JWTEncoder),
        new HttpExceptionConverterMiddleware(),
        $controller,
    );
    $transportRefl = new ReflectionClass($transport);
    $updates = $transportRefl->getProperty('updates');
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
    $response = handle($app, $request);
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
    $controller = new PublishController();

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
    $controller = new PublishController();

    // Given
    $jwt = $JWTEncoder->encode(['mercure' => ['publish' => ['*']]]) . 'foo';
    $request = with_token(
        new ServerRequest(
            'POST',
            '/.well-known/mercure',
            [
                'Authorization' => "Bearer $jwt",
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'topic=/foo&data=bar'
        ),
        $jwt
    );

    // When
    $controller($request);
})->throws(
    AccessDeniedHttpException::class,
    'Invalid JWT Token'
);

it('complains if JWT does not contain a mercure.publish claim', function () {
    $JWTEncoder = Auth::getJWTEncoder();
    $controller = new PublishController();

    // Given
    $jwt = $JWTEncoder->encode([]);
    $request = with_token(
        new ServerRequest(
            'POST',
            '/.well-known/mercure',
            [
                'Authorization' => "Bearer $jwt",
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'topic=/foo&data=bar'
        ),
        $jwt
    );

    // When
    $controller($request);
})->throws(
    AccessDeniedHttpException::class,
    'Missing mercure.publish claim.'
);

it('yells when no topic is provided', function () {
    $controller = new PublishController();

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
    $controller = new PublishController();

    // Given
    $jwt = $JWTEncoder->encode(['mercure' => ['publish' => ['/bar']]]);
    $request = with_token(
        new ServerRequest(
            'POST',
            '/.well-known/mercure',
            [
                'Authorization' => "Bearer $jwt",
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'topic=/foo&data=bar&private=yes'
        ),
        $jwt
    );

    // When
    $controller($request);

    // Then
})->throws(
    AccessDeniedHttpException::class,
    'Your rights are not sufficient to publish this update.'
);
