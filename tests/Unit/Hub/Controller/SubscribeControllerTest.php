<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Controller;

use DateTimeImmutable;
use Freddie\Hub\Controller\SubscribeController;
use Freddie\Hub\Hub;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Security\BearerTokenException;
use Generator;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\Message\ServerRequest;
use React\Promise\PromiseInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

use function Freddie\Tests\subscribe_controller;
use function Freddie\Tests\access_token;
use function Freddie\Tests\create_jwt;
use function Freddie\Tests\detail;
use function Freddie\Tests\run_loop;
use function Freddie\Tests\with_token;

it('receives updates and dumps them into the stream', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();

    // Given
    $hey = new Message(data: 'Hey!');
    $hello = new Message(data: 'Hello');
    $world = new Message(data: 'World!');
    $sensitive = new Message(data: 'S3cr3tC0de', private: true);
    $transport->publish(new Update(['/bar'], $hey)); // Should not be dumped into stream
    $transport->publish(new Update(['/foo'], $hello));
    $request = new ServerRequest('GET', '/.well-known/mercure?match=/foo', ['Last-Event-ID' => 'earliest']);

    // When
    $response = $controller($request, $stream);
    run_loop();
    $transport->publish(new Update(['/foo'], $world));
    $transport->publish(new Update(['/foo'], $sensitive)); // Should not be dumped into stream

    // Then
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/event-stream')
        ->and($response->getHeaderLine('Incremental'))->toBe('?1')
        ->and($response->getHeaderLine('Accept-Query'))->toBe('application/x-www-form-urlencoded')
        ->and($response->getHeaderLine('Mercure-Last-Event-ID'))->toBe('earliest')
        ->and($response->hasHeader('Last-Event-ID'))->toBeFalse()
        ->and($stream->storage)->toBe([(string) $hello, (string) $world]);
});

it('matches topics with URL patterns', function () {
    $transport = new PHPTransport();
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();
    $request = new ServerRequest(
        'GET',
        '/.well-known/mercure?match_urlpattern=' . urlencode('https://example.com/books/:id') . '&match=/foo',
    );

    // When
    $controller($request, $stream);
    run_loop();
    $transport->publish(new Update(['https://example.com/books/1'], $book = new Message(data: 'book')));
    $transport->publish(new Update(['https://example.com/authors/1'], new Message(data: 'author')));
    $transport->publish(new Update(['/foo'], $foo = new Message(data: 'foo')));

    // Then
    expect($stream->storage)->toBe([(string) $book, (string) $foo]);
});

it('receives private updates when authorized', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();

    // Given
    $hey = new Message(data: 'Hey!', private: true); // Should not be dumped into stream
    $hello = new Message(data: 'Hello');
    $world = new Message(data: 'World!');
    $sensitive = new Message(data: 'S3cr3tC0de', private: true);
    $transport->publish(new Update(['/bar'], $hey));
    $transport->publish(new Update(['/foo'], $hello));
    $jwt = access_token([detail(['subscribe'], ['/foo'])]);
    $request = with_token(
        new ServerRequest('GET', '/.well-known/mercure?match=/foo&match=/bar', ['Last-Event-ID' => 'earliest']),
        $jwt,
    );

    // When
    $controller($request, $stream);
    run_loop();
    $transport->publish(new Update(['/foo'], $world));
    $transport->publish(new Update(['/foo'], $sensitive));

    // Then
    expect($stream->storage)->toBe([(string) $hello, (string) $world, (string) $sensitive]);
});

it('replays the updates following the last event ID', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();

    // Given
    $transport->publish(new Update(['/foo'], $first = new Message(id: 'first')));
    $transport->publish(new Update(['/foo'], $second = new Message(id: 'second')));
    $request = new ServerRequest('GET', '/.well-known/mercure?match=/foo&last_event_id=first');

    // When
    $response = $controller($request, $stream);
    run_loop();

    // Then
    expect($response->getHeaderLine('Mercure-Last-Event-ID'))->toBe('first')
        ->and($stream->storage)->toBe([(string) $second]);
});

it('gives the earliest cursor when the last event ID is unknown or empty', function (string $query, array $headers) {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();

    // Given
    $transport->publish(new Update(['/foo'], new Message(id: 'first')));
    $transport->publish(new Update(['/foo'], new Message(id: 'second')));
    $request = new ServerRequest('GET', '/.well-known/mercure?match=/foo' . $query, $headers);

    // When
    $response = $controller($request, $stream);
    run_loop();

    // Then: nothing precedes the first event the subscriber will receive
    expect($response->getHeaderLine('Mercure-Last-Event-ID'))->toBe('earliest')
        ->and($stream->storage)->toBe([]);
})->with([
    'unknown ID' => ['&last_event_id=unknown', []],
    'empty query parameter' => ['&last_event_id=', []],
    'empty header' => ['', ['Last-Event-ID' => '']],
]);

it('does not send the cursor when no last event ID is requested', function () {
    $response = subscribe_controller()(new ServerRequest('GET', '/.well-known/mercure?match=/foo'));

    expect($response->hasHeader('Mercure-Last-Event-ID'))->toBeFalse();
});

it('does not lose updates published after the history was read', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();
    $transport->publish(new Update(['/foo'], $first = new Message(id: 'first')));

    // When: an update is published between the subscription and the flush of the missed ones
    $controller(new ServerRequest('GET', '/.well-known/mercure?match=/foo&last_event_id=earliest'), $stream);
    $transport->publish(new Update(['/foo'], $second = new Message(id: 'second')));
    run_loop();

    // Then
    expect($stream->storage)->toBe([(string) $first, (string) $second]);
});

it('does not duplicate updates published while the history is read', function () {
    // A transport reading its history asynchronously (like Redis): an update published meanwhile is both
    // dispatched live and found in the history.
    $inner = new PHPTransport(size: 1000);
    $transport = new class ($inner) implements TransportInterface {
        public ?Update $publishedWhileReading = null;

        public function __construct(private PHPTransport $inner)
        {
        }

        public function publish(Update $update): PromiseInterface
        {
            return $this->inner->publish($update);
        }

        public function subscribe(callable $callback): void
        {
            $this->inner->subscribe($callback);
        }

        public function unsubscribe(callable $callback): void
        {
            $this->inner->unsubscribe($callback);
        }

        public function reconciliate(string $lastEventID): Generator
        {
            if (null !== $this->publishedWhileReading) {
                $this->inner->publish($this->publishedWhileReading);
            }

            return yield from $this->inner->reconciliate($lastEventID);
        }
    };
    $controller = (new SubscribeController())
        ->setHub(new Hub(transport: $transport, options: ['heartbeat_interval' => 0]));
    $stream = new ThroughStreamStub();
    $inner->publish(new Update(['/foo'], $first = new Message(id: 'first')));
    $transport->publishedWhileReading = new Update(['/foo'], $second = new Message(id: 'second'));

    // When
    $controller(new ServerRequest('GET', '/.well-known/mercure?match=/foo&last_event_id=earliest'), $stream);
    run_loop();

    // Then
    expect($stream->storage)->toBe([(string) $first, (string) $second]);
});

it('sends every update reusing an ID', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();
    $transport->publish(new Update(['/foo'], new Message(id: 'start')));
    $transport->publish(new Update(['/foo'], $first = new Message(id: 'reused', data: 'first')));
    $transport->publish(new Update(['/foo'], $second = new Message(id: 'reused', data: 'second')));

    // When
    $controller(new ServerRequest('GET', '/.well-known/mercure?match=/foo&last_event_id=start'), $stream);
    run_loop();

    // Then
    expect($stream->storage)->toBe([(string) $first, (string) $second]);
});

it('closes the connection when the access token expires', function () {
    $transport = new PHPTransport();
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();
    $closed = false;
    $stream->on('close', function () use (&$closed) {
        $closed = true;
    });
    $request = with_token(
        new ServerRequest('GET', '/.well-known/mercure?match=/foo'),
        access_token([detail(['subscribe'], ['/foo'])], ['exp' => new DateTimeImmutable('+50 milliseconds')]),
    );

    // When: an update is delivered before the expiration, another one after
    $controller($request, $stream);
    run_loop();
    $transport->publish(new Update(['/foo'], $before = new Message(data: 'before', private: true)));
    run_loop(0.1);
    $closedOnExpiration = $closed;
    $transport->publish(new Update(['/foo'], new Message(data: 'after', private: true)));

    // Then
    expect($closedOnExpiration)->toBeTrue()
        ->and($stream->storage)->toBe([(string) $before]);
});

it('does not replay missed updates with an expired access token', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();
    $transport->publish(new Update(['/foo'], new Message(data: 'missed')));
    $closed = false;
    $stream->on('close', function () use (&$closed) {
        $closed = true;
    });
    // Signature and expiration are not validated by with_token()
    $request = with_token(
        new ServerRequest('GET', '/.well-known/mercure?match=/foo&last_event_id=earliest'),
        access_token([detail(['subscribe'], ['/foo'])], ['exp' => new DateTimeImmutable('-1 second')]),
    );

    // When
    $controller($request, $stream);
    run_loop();

    // Then
    expect($closed)->toBeTrue()
        ->and($stream->storage)->toBe([]);
});

it('keeps connections without token expiration open', function () {
    $controller = subscribe_controller(options: ['protocol_compatibility' => 8]);
    $stream = new ThroughStreamStub();
    $closed = false;
    $stream->on('close', function () use (&$closed) {
        $closed = true;
    });
    $request = with_token(
        new ServerRequest('GET', '/.well-known/mercure?topic=/foo'),
        create_jwt(['mercure' => ['subscribe' => ['/foo']]]),
        legacy: true,
    );

    $controller($request, $stream);
    run_loop(0.05);

    expect($closed)->toBeFalse();
});

it('subscribes the legacy way in compatibility mode', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport, ['protocol_compatibility' => 8]);
    $stream = new ThroughStreamStub();

    // Given
    $transport->publish(new Update(['/foo/1'], $hello = new Message(id: 'hello')));
    $transport->publish(new Update(['/foo/2'], $secret = new Message(id: 'secret', private: true)));
    $jwt = create_jwt(['mercure' => ['subscribe' => ['/foo/{id}']]]);
    $request = with_token(
        new ServerRequest('GET', '/.well-known/mercure?topic=' . urlencode('/foo/{id}') . '&lastEventID=earliest'),
        $jwt,
        legacy: true,
    );

    // When
    $response = $controller($request, $stream);
    run_loop();

    // Then
    expect($response->getHeaderLine('Mercure-Last-Event-ID'))->toBe('earliest')
        ->and($response->getHeaderLine('Last-Event-ID'))->toBe('earliest')
        ->and($stream->storage)->toBe([(string) $hello, (string) $secret]);
});

it('accepts deprecated last event ID parameters in compatibility mode', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport, ['protocol_compatibility' => 8]);
    $transport->publish(new Update(['/foo'], new Message(id: 'first')));

    $response = $controller(new ServerRequest('GET', '/.well-known/mercure?topic=/foo&Last-Event-ID=first'));

    expect($response->getHeaderLine('Mercure-Last-Event-ID'))->toBe('first');
});

it('ignores legacy last event ID parameters outside compatibility mode', function () {
    $response = subscribe_controller()(new ServerRequest('GET', '/.well-known/mercure?match=/foo&lastEventID=x'));

    expect($response->hasHeader('Mercure-Last-Event-ID'))->toBeFalse();
});

it('subscribes with the QUERY method', function () {
    $transport = new PHPTransport();
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();
    $request = new ServerRequest(
        'QUERY',
        '/.well-known/mercure?match=/foo',
        ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
        'match=/bar&match=/baz&last_event_id=earliest',
    );

    // When
    $response = $controller($request, $stream);
    run_loop();
    foreach (['/foo', '/bar', '/baz', '/qux'] as $topic) {
        $transport->publish(new Update([$topic], new Message(id: $topic)));
    }

    // Then
    expect($response->getHeaderLine('Mercure-Last-Event-ID'))->toBe('earliest')
        ->and($stream->storage)->toHaveCount(3);
});

it('rejects invalid subscription requests', function (ServerRequest $request, string $exception, string $message) {
    expect(fn () => subscribe_controller()($request))->toThrow($exception, $message);
})->with(function () {
    $form = ['Content-Type' => 'application/x-www-form-urlencoded'];

    yield 'no matcher' => [
        new ServerRequest('GET', '/.well-known/mercure'),
        BadRequestHttpException::class,
        'Missing "match" parameter.',
    ];
    yield 'legacy topic parameter' => [
        new ServerRequest('GET', '/.well-known/mercure?topic=/foo'),
        BadRequestHttpException::class,
        'The "topic" parameter is not supported anymore',
    ];
    yield 'case-sensitive parameter' => [
        new ServerRequest('GET', '/.well-known/mercure?Match=/foo'),
        BadRequestHttpException::class,
        'Unknown topic matcher parameter "Match".',
    ];
    yield 'unknown matcher type' => [
        new ServerRequest('GET', '/.well-known/mercure?match_regexp=/foo'),
        BadRequestHttpException::class,
        'Unknown topic matcher parameter "match_regexp".',
    ];
    yield 'internal matcher type' => [
        new ServerRequest('GET', '/.well-known/mercure?match__legacy=/foo'),
        BadRequestHttpException::class,
        'Unknown topic matcher parameter "match__legacy".',
    ];
    yield 'invalid pattern' => [
        new ServerRequest('GET', '/.well-known/mercure?match_urlpattern=' . urlencode('/foo/(')),
        BadRequestHttpException::class,
        'Invalid topic matcher pattern (urlpattern).',
    ];
    yield 'too many matchers' => [
        new ServerRequest('GET', '/.well-known/mercure?' . str_repeat('match=/foo&', 101)),
        BadRequestHttpException::class,
        'Too many matchers (max 100).',
    ];
    yield 'not acceptable' => [
        new ServerRequest('GET', '/.well-known/mercure?match=/foo', ['Accept' => 'application/json']),
        NotAcceptableHttpException::class,
        'The request does not accept text/event-stream.',
    ];
    yield 'explicitly refused' => [
        new ServerRequest('GET', '/.well-known/mercure?match=/foo', ['Accept' => 'text/event-stream;q=0, */*']),
        NotAcceptableHttpException::class,
        'The request does not accept text/event-stream.',
    ];
    yield 'QUERY without content type' => [
        new ServerRequest('QUERY', '/.well-known/mercure', [], 'match=/foo'),
        BadRequestHttpException::class,
        'Missing Content-Type header.',
    ];
    yield 'QUERY with unsupported content type' => [
        new ServerRequest('QUERY', '/.well-known/mercure', ['Content-Type' => 'application/json'], '{}'),
        UnsupportedMediaTypeHttpException::class,
        'Unsupported media type, use "application/x-www-form-urlencoded".',
    ];
    yield 'QUERY without matcher' => [
        new ServerRequest('QUERY', '/.well-known/mercure', $form, 'foo=bar'),
        BadRequestHttpException::class,
        'Missing "match" parameter.',
    ];
});

it('negotiates the event stream', function (string $accept) {
    $request = new ServerRequest('GET', '/.well-known/mercure?match=/foo', ['Accept' => $accept]);

    $response = subscribe_controller()($request);

    expect($response->getStatusCode())->toBe(200);
})->with([
    '',
    ' , ',
    'text/event-stream',
    'text/*',
    '*/*',
    'application/json, text/event-stream;q=0.5',
    '*/*;q=0, text/event-stream',
    'text/html, text/*;q=0.1',
]);

it('yells when anonymous subscriptions are forbidden and user doesn\'t provide a JWT', function () {
    $controller = subscribe_controller(options: ['allow_anonymous' => false]);

    try {
        $controller(new ServerRequest('GET', '/.well-known/mercure?match=/foo'));
        $this->fail('An exception should have been thrown.');
    } catch (BearerTokenException $e) {
        expect($e->getStatusCode())->toBe(401)
            ->and($e->getMessage())->toBe('Anonymous subscriptions are not allowed on this hub.')
            ->and($e->getHeaders())->toBe(['WWW-Authenticate' => 'Bearer']);
    }
});

it('accepts authenticated subscribers when anonymous subscriptions are forbidden', function () {
    $controller = subscribe_controller(options: ['allow_anonymous' => false]);
    $request = with_token(new ServerRequest('GET', '/.well-known/mercure?match=/foo'), access_token());

    expect($controller($request)->getStatusCode())->toBe(200);
});

it('complains if JWT is invalid', function () {
    $jwt = access_token([detail(['subscribe'], ['*'])]) . 'foo';

    with_token(new ServerRequest('GET', '/.well-known/mercure?match=/foo'), $jwt);
})->throws(BearerTokenException::class, 'Error while decoding from Base64Url, invalid base64 characters detected');

it('complains if the authorization details are malformed', function () {
    $controller = subscribe_controller();
    $request = with_token(
        new ServerRequest('GET', '/.well-known/mercure?match=/foo'),
        access_token([detail(['subscribe'], [['match' => '*', 'match_type' => 'regexp']])]),
    );

    $controller($request);
})->throws(BearerTokenException::class, 'Invalid authorization_details claim: unsupported topic matcher type.');

it('unsubscribes from transport whenever connection closes', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = subscribe_controller($transport);
    $stream = new ThroughStreamStub();

    // Given
    $hello = new Message(data: 'Hello');
    $world = new Message(data: 'World!');
    $transport->publish(new Update(['/foo'], $hello));
    $request = new ServerRequest('GET', '/.well-known/mercure?match=/foo', ['Last-Event-ID' => 'earliest']);

    // When
    $controller($request, $stream);
    Loop::futureTick(fn () => $stream->close());
    Loop::futureTick(fn () => $transport->publish(new Update(['/foo'], $world)));
    run_loop();

    // Then
    expect($stream->storage)->toBe([(string) $hello]);
});

it('dispatches subscription events', function () {
    $transport = new PHPTransport();
    $hub = new Hub(transport: $transport, options: ['heartbeat_interval' => 0, 'subscriptions' => true]);
    $controller = (new SubscribeController())->setHub($hub);
    $observerStream = new ThroughStreamStub();
    $stream = new ThroughStreamStub();

    // Given: an observer allowed to receive subscription events
    $observer = with_token(
        new ServerRequest(
            'GET',
            '/.well-known/mercure?match_urlpattern=' . urlencode('/.well-known/mercure/subscriptions/*'),
        ),
        access_token([
            detail(
                ['subscribe'],
                [['match' => '/.well-known/mercure/subscriptions/*', 'match_type' => 'urlpattern']],
                ['role' => 'observer'],
            ),
        ]),
    );
    $controller($observer, $observerStream);
    run_loop();

    // When: someone subscribes, then leaves
    $subscriber = with_token(
        new ServerRequest('GET', '/.well-known/mercure?match=' . urlencode('/books/1')),
        access_token([detail(['subscribe'], ['/books/1'], ['user' => 'alice'])]),
    );
    $controller($subscriber, $stream);
    run_loop();
    $subscriberId = $hub->getSubscribers()[1]->id;
    $stream->close();
    run_loop();

    // Then
    // (the observer is notified of its own subscription too)
    $expectedId = '/.well-known/mercure/subscriptions/exact/%2Fbooks%2F1/' . rawurlencode($subscriberId);
    expect($observerStream->storage)->toHaveCount(3)
        ->and($observerStream->storage[0])->toContain('"match_type":"urlpattern","active":true')
        ->and($observerStream->storage[1])->toContain("event: mercure\n")
        ->and($observerStream->storage[1])->toContain(
            'data: {"id":"' . $expectedId . '","type":"subscription","subscriber":"' . $subscriberId . '",'
            . '"match":"/books/1","match_type":"exact","active":true,"payload":{"user":"alice"}}'
        )
        ->and($observerStream->storage[2])->toContain('"active":false');
});

it('does not dispatch subscription events to unauthorized subscribers', function () {
    $transport = new PHPTransport();
    $hub = new Hub(transport: $transport, options: ['heartbeat_interval' => 0, 'subscriptions' => true]);
    $controller = (new SubscribeController())->setHub($hub);
    $observerStream = new ThroughStreamStub();

    // Given: an anonymous observer
    $controller(new ServerRequest('GET', '/.well-known/mercure?match=*'), $observerStream);
    run_loop();

    // When
    $controller(new ServerRequest('GET', '/.well-known/mercure?match=/foo'));
    run_loop();

    // Then
    expect($observerStream->storage)->toBe([]);
});

it('writes periodic heartbeats and cancels the timer when the stream is closed', function () {
    $controller = new SubscribeController();
    $controller->setHub(new Hub(options: ['heartbeat_interval' => 0.01]));
    $stream = new ThroughStreamStub();

    // Given
    $request = new ServerRequest('GET', '/.well-known/mercure?match=/foo');

    // When: a few heartbeats have time to fire
    $controller($request, $stream);
    Loop::addTimer(0.025, fn () => Loop::stop());
    Loop::run();

    $beats = fn () => count(array_filter($stream->storage, fn ($chunk) => ":\n" === $chunk));

    // Then: heartbeats were written on the interval
    $before = $beats();
    expect($before)->toBeGreaterThanOrEqual(1);

    // When: the stream is closed (e.g. a graceful client disconnect)
    $stream->close();
    Loop::addTimer(0.025, fn () => Loop::stop());
    Loop::run();

    // Then: the timer was cancelled, so no further heartbeats are written
    expect($beats())->toBe($before);
});

// The actual reaping of a "gone" client is delegated to React/TCP: a heartbeat
// write to a half-open socket eventually fails, React emits 'close', and the
// existing 'close' handler unsubscribes. DeadStreamStub simulates that failed
// write so we can assert the Freddie-side chain (write -> close -> unsubscribe).
it('reaps a gone client when a heartbeat write closes the dead stream', function () {
    $transport = new PHPTransport(size: 1000);
    $controller = new SubscribeController();
    $controller->setHub(new Hub(transport: $transport, options: ['heartbeat_interval' => 0.01]));
    $stream = new DeadStreamStub();

    // Given: a subscriber whose client has silently gone away
    $request = new ServerRequest('GET', '/.well-known/mercure?match=/foo');
    $controller($request, $stream);

    // When: the heartbeat fires and its write hits the dead peer
    Loop::addTimer(0.03, fn () => Loop::stop());
    Loop::run();

    // Then: a single heartbeat was attempted, which closed the stream and
    // cancelled the timer (no repeated writes)
    expect($stream->writes)->toBe([":\n"]);

    // And: the subscriber was unsubscribed, so a later update is not delivered
    $transport->publish(new Update(['/foo'], new Message(data: 'after')));
    expect($stream->writes)->toBe([":\n"]);
});

it('does not write heartbeats when the interval is zero', function () {
    $controller = subscribe_controller();
    $stream = new ThroughStreamStub();

    // When
    $controller(new ServerRequest('GET', '/.well-known/mercure?match=/foo'), $stream);
    Loop::addTimer(0.025, fn () => Loop::stop());
    Loop::run();

    // Then
    expect($stream->storage)->toBe([]);
});
