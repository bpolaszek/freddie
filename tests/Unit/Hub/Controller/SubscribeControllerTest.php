<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Controller;

use FrameworkX\App;
use Freddie\Hub\Controller\SubscribeController;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Message\Message;
use Freddie\Message\Update;
use React\EventLoop\Loop;
use React\Http\Message\ServerRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

use function Freddie\Tests\create_jwt;
use function Freddie\Tests\with_token;

it('receives updates and dumps them into the stream', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = new SubscribeController(['allow_anonymous' => true], $transport);
    $app = new App(
        new HttpExceptionConverterMiddleware(),
        $controller,
    );
    $stream = new ThroughStreamStub();

    // Given
    $hey = new Message(data: 'Hey!');
    $hello = new Message(data: 'Hello');
    $world = new Message(data: 'World!');
    $sensitive = new Message(data: 'S3cr3tC0de', private: true);
    $transport->publish(new Update(['/bar'], $hey)); // Should not be dumped into stream
    $transport->publish(new Update(['/foo'], $hello));
    $request = new ServerRequest(
        'GET',
        '/.well-known/mercure?topic=/foo',
        ['Last-Event-ID' => 'earliest'],
    );

    // When
    $response = $controller($request, $stream);
    Loop::addTimer(0.01, fn () => Loop::stop());
    Loop::run();
    $transport->publish(new Update(['/foo'], $world));
    $transport->publish(new Update(['/foo'], $sensitive)); // Should not be dumped into stream

    // Then
    expect($response->getStatusCode())->toBe(200);
    expect($response->getHeaderLine('Content-Type'))->toBe('text/event-stream');
    expect($stream->storage)->toHaveCount(2);
    expect($stream->storage[0])->toBe((string) $hello);
    expect($stream->storage[1])->toBe((string) $world);
});

it('receives private updates when authorized', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = new SubscribeController(['allow_anonymous' => true], $transport);
    $stream = new ThroughStreamStub();

    // Given
    $hey = new Message(data: 'Hey!', private: true); // Should not be dumped into stream
    $hello = new Message(data: 'Hello');
    $world = new Message(data: 'World!');
    $sensitive = new Message(data: 'S3cr3tC0de', private: true);
    $transport->publish(new Update(['/bar'], $hey)); // Should not be dumped into stream
    $transport->publish(new Update(['/foo'], $hello));
    $jwt = create_jwt(['mercure' => ['subscribe' => ['/foo']]]);
    $request = with_token(
        new ServerRequest(
            'GET',
            '/.well-known/mercure?topic=/foo&topic=/bar',
            [
                'Authorization' => "Bearer $jwt",
                'Last-Event-ID' => 'earliest',
            ],
        ),
        $jwt
    );

    // When
    $response = $controller($request, $stream);
    Loop::addTimer(0.01, fn () => Loop::stop());
    Loop::run();
    $transport->publish(new Update(['/foo'], $world));
    $transport->publish(new Update(['/foo'], $sensitive));

    // Then
    expect($response->getStatusCode())->toBe(200);
    expect($response->getHeaderLine('Content-Type'))->toBe('text/event-stream');
    expect($stream->storage)->toHaveCount(3);
    expect($stream->storage[0])->toBe((string) $hello);
    expect($stream->storage[1])->toBe((string) $world);
    expect($stream->storage[2])->toBe((string) $sensitive);
});

it('yells if user doesn\'t subscribe to at least one topic', function () {
    $controller = new SubscribeController(['allow_anonymous' => true]);

    // Given
    $request = new ServerRequest(
        'GET',
        '/.well-known/mercure',
        ['Last-Event-ID' => 'earliest'],
    );

    // When
    $controller($request);

    // Then
})->throws(
    BadRequestHttpException::class,
    'Missing topic parameter.'
);

it('yells when anonymous subscriptions are forbidden and user doesn\'t provide a JWT', function () {
    $controller = new SubscribeController(['allow_anonymous' => false]);

    // Given
    $request = new ServerRequest(
        'GET',
        '/.well-known/mercure?topic=/foo',
        ['Last-Event-ID' => 'earliest'],
    );

    // When
    $controller($request);

    // Then
})->throws(
    AccessDeniedHttpException::class,
    'Anonymous subscriptions are not allowed on this hub.'
);

it('complains if JWT is invalid', function () {
    $controller = new SubscribeController(['allow_anonymous' => false]);

    // Given
    $jwt = create_jwt(['mercure' => ['publish' => ['*']]]) . 'foo';
    $request = with_token(
        new ServerRequest(
            'GET',
            '/.well-known/mercure?topic=/foo',
            [
                'Authorization' => "Bearer $jwt",
                'Last-Event-ID' => 'earliest',
            ],
        ),
        $jwt
    );

    // When
    $controller($request);

    // Then
})->throws(
    AccessDeniedHttpException::class,
    'Error while decoding from Base64Url, invalid base64 characters detected'
);
