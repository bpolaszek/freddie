<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Controller;

use Fig\Http\Message\StatusCodeInterface;
use FrameworkX\App;
use Freddie\Hub\Controller\PublishController;
use Freddie\Hub\Hub;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Security\BearerTokenException;
use Freddie\Security\Grants;
use Generator;
use React\Http\Message\ServerRequest;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Ulid;
use Throwable;

use function Freddie\Tests\publish_app;
use function Freddie\Tests\published_update;
use function Freddie\Tests\publish_request;
use function Freddie\Tests\access_token;
use function Freddie\Tests\access_token_policy;
use function Freddie\Tests\create_jwt;
use function Freddie\Tests\detail;
use function Freddie\Tests\handle;
use function Freddie\Tests\jwt_config;
use function Freddie\Tests\with_token;
use function React\Promise\reject;

it('publishes updates to the hub', function (
    string $payload,
    string $jwt,
    int $expectedStatus,
    ?Update $expectedUpdate,
) {
    [$app, $transport] = publish_app();

    // When
    $response = handle($app, publish_request($jwt, $payload));

    // Then
    expect($response->getStatusCode())->toBe($expectedStatus)
        ->and(published_update($transport))->toEqual($expectedUpdate);
    if (200 === $expectedStatus) {
        expect((string) $response->getBody())->toBe($expectedUpdate->message->id)
            ->and($response->getHeaderLine('Content-Type'))->toBe('text/plain; charset=utf-8');
    } else {
        expect($response->getHeaderLine('WWW-Authenticate'))->toBe('Bearer error="insufficient_scope"');
    }
})->with(function () {
    $id = Ulid::generate();
    $all = access_token([detail(['publish'], ['*'])]);
    $foo = access_token([detail(['publish'], ['/foo'])]);
    $subscribeOnly = access_token([detail(['subscribe'], ['*'])]);

    yield 'private' => [
        'payload' => 'topic=/foo&topic=/bar&data=foobar&type=alert&retry=2&private=on&id=' . $id,
        'jwt' => $all,
        'expectedStatus' => 200,
        'expectedUpdate' => new Update(['/foo', '/bar'], new Message($id, 'foobar', true, 'alert', 2)),
    ];
    yield 'private, any value' => [
        'payload' => 'topic=/foo&private=false&id=' . $id,
        'jwt' => $all,
        'expectedStatus' => 200,
        'expectedUpdate' => new Update(['/foo'], new Message($id, null, true)),
    ];
    yield 'public' => [
        'payload' => 'topic=/foo&data=foobar&event=ignored&id=' . $id,
        'jwt' => $foo,
        'expectedStatus' => 200,
        'expectedUpdate' => new Update(['/foo'], new Message($id, 'foobar')),
    ];
    yield 'URL pattern grant' => [
        'payload' => 'topic=https://example.com/books/1&id=' . $id,
        'jwt' => access_token([
            detail(['publish'], [['match' => 'https://example.com/books/:id', 'match_type' => 'urlpattern']]),
        ]),
        'expectedStatus' => 200,
        'expectedUpdate' => new Update(['https://example.com/books/1'], new Message($id)),
    ];
    yield 'public update requires a grant on every topic' => [
        'payload' => 'topic=/foo&topic=/bar&data=foobar',
        'jwt' => $foo,
        'expectedStatus' => 403,
        'expectedUpdate' => null,
    ];
    yield 'no publish grant' => [
        'payload' => 'topic=/foo&data=foobar',
        'jwt' => $subscribeOnly,
        'expectedStatus' => 403,
        'expectedUpdate' => null,
    ];
});

it('publishes updates the legacy way in compatibility mode', function (
    string $payload,
    string $jwt,
    int $expectedStatus,
    ?Update $expectedUpdate,
) {
    [$app, $transport] = publish_app(['protocol_compatibility' => 8]);

    // When
    $response = handle($app, publish_request($jwt, $payload));

    // Then
    expect($response->getStatusCode())->toBe($expectedStatus)
        ->and(published_update($transport))->toEqual($expectedUpdate);
})->with(function () {
    $id = Ulid::generate();

    yield 'private' => [
        'payload' => 'topic=/foo&topic=/bar&data=foobar&event=alert&retry=2&private=true&id=' . $id,
        'jwt' => create_jwt(['mercure' => ['publish' => ['*']]]),
        'expectedStatus' => 200,
        'expectedUpdate' => new Update(['/foo', '/bar'], new Message($id, 'foobar', true, 'alert', 2)),
    ];
    yield 'private=false is public' => [
        'payload' => 'topic=/foo&private=false&type=alert&id=' . $id,
        'jwt' => create_jwt(['mercure' => ['publish' => []]]),
        'expectedStatus' => 200,
        'expectedUpdate' => new Update(['/foo'], new Message($id, null, false, 'alert')),
    ];
    yield 'public updates only require the claim' => [
        'payload' => 'topic=/foo&topic=/bar&data=foobar&id=' . $id,
        'jwt' => create_jwt(['mercure' => ['publish' => []]]),
        'expectedStatus' => 200,
        'expectedUpdate' => new Update(['/foo', '/bar'], new Message($id, 'foobar')),
    ];
    yield 'private updates require a grant on every topic' => [
        'payload' => 'topic=/foo&topic=/bar&private=true',
        'jwt' => create_jwt(['mercure' => ['publish' => ['/foo']]]),
        'expectedStatus' => 403,
        'expectedUpdate' => null,
    ];
    yield 'missing claim' => [
        'payload' => 'topic=/foo',
        'jwt' => create_jwt([]),
        'expectedStatus' => 403,
        'expectedUpdate' => null,
    ];
    yield 'authorization details' => [
        'payload' => 'topic=/foo&id=' . $id,
        'jwt' => create_jwt(['authorization_details' => [detail(['publish'], ['/foo'])]]),
        'expectedStatus' => 200,
        'expectedUpdate' => new Update(['/foo'], new Message($id)),
    ];
});

it('complains when no jwt is provided', function () {
    [$app] = publish_app();

    // When
    $response = handle($app, new ServerRequest(
        'POST',
        '/.well-known/mercure',
        ['Content-Type' => 'application/x-www-form-urlencoded'],
        'topic=/foo&data=bar',
    ));

    // Then
    expect($response->getStatusCode())->toBe(401)
        ->and($response->getHeaderLine('WWW-Authenticate'))->toBe('Bearer')
        ->and((string) $response->getBody())->toBe('You must be authenticated to publish on this hub.');
});

it('complains when JWT is invalid', function () {
    [$app] = publish_app();

    // When
    $response = handle($app, publish_request(access_token([detail(['publish'], ['*'])]) . 'foo', 'topic=/foo'));

    // Then
    expect($response->getStatusCode())->toBe(401)
        ->and($response->getHeaderLine('WWW-Authenticate'))->toBe('Bearer error="invalid_token"');
});

it('rejects invalid updates', function (string $payload, string $expectedMessage) {
    $controller = new PublishController();
    $controller->setHub(new Hub());
    $request = with_token(publish_request($jwt = access_token([detail(['publish'], ['*'])]), $payload), $jwt);

    expect(fn () => $controller($request))->toThrow(BadRequestHttpException::class, $expectedMessage);
})->with(function () {
    yield 'no topic' => ['data=bar', 'Missing "topic" parameter.'];
    yield 'too many topics' => [str_repeat('topic=/foo&', 1001), 'Too many topics (max 1000).'];
    yield 'invalid topic' => ['topic=' . urlencode("/foo\n"), 'Invalid topic'];
    yield 'too long topic' => ['topic=' . str_repeat('a', 4097), 'Invalid topic'];
    yield 'wildcard topic' => ['topic=*', 'The "*" topic is reserved for the wildcard matcher.'];
    yield 'reserved topic' => [
        'topic=/foo&topic=' . urlencode('/.well-known/mercure/subscriptions/exact/foo/bar'),
        'resolves into the reserved "/.well-known/mercure" namespace',
    ];
    yield 'reserved topic, absolute' => [
        'topic=' . urlencode('https://example.com/.well-known/mercure'),
        'resolves into the reserved "/.well-known/mercure" namespace',
    ];
    yield 'id with a line feed' => ['topic=/foo&id=' . urlencode("foo\nid:bar"), 'Invalid "id" parameter.'];
    yield 'id with a carriage return' => ['topic=/foo&id=' . urlencode("foo\rbar"), 'Invalid "id" parameter.'];
    yield 'id with NUL' => ['topic=/foo&id=' . urlencode("foo\0bar"), 'Invalid "id" parameter.'];
    yield 'reserved id' => ['topic=/foo&id=earliest', 'Invalid "id" parameter.'];
    yield 'id starting with #' => ['topic=/foo&id=%23foo', 'Invalid "id" parameter.'];
    yield 'too long id' => ['topic=/foo&id=' . str_repeat('a', 1025), 'Invalid "id" parameter.'];
    yield 'type with a line feed' => ['topic=/foo&type=' . urlencode("foo\ndata:bar"), 'Invalid "type" parameter.'];
    yield 'reserved type' => ['topic=/foo&type=mercure', 'The "mercure" type is reserved to the hub.'];
    yield 'invalid retry' => ['topic=/foo&retry=soon', 'Invalid "retry" parameter.'];
    yield 'invalid data' => ['topic=/foo&data=' . urlencode("\xC3"), 'The "data" parameter is not valid UTF-8.'];
});

it('rejects a malformed authorization details claim', function () {
    $controller = new PublishController();
    $controller->setHub(new Hub());
    $jwt = access_token([['type' => Grants::AUTHORIZATION_DETAIL_TYPE, 'actions' => ['publish'], 'topics' => ['*']]]);

    $controller(with_token(publish_request($jwt, 'topic=/foo'), $jwt));
})->throws(BearerTokenException::class, 'Invalid authorization_details claim: topic entries must be objects.');

it('complains when publishing fails', function (Throwable $exception, int $expectedStatusCode) {
    $transport = new class ($exception) implements TransportInterface {
        public function __construct(private Throwable $exception)
        {
        }

        public function publish(Update $update): PromiseInterface
        {
            return reject($this->exception);
        }

        public function subscribe(callable $callback): void
        {
        }

        public function unsubscribe(callable $callback): void
        {
        }

        public function reconciliate(string $lastEventID): Generator
        {
            yield from [];

            return false;
        }
    };
    [$app] = publish_app(transport: $transport);

    // When
    $response = handle($app, publish_request(
        access_token([detail(['publish'], ['*'])]),
        'topic=/foo&topic=/bar&data=foobar&private=true&id=' . Ulid::generate(),
    ));

    // Then
    expect($response->getStatusCode())->toBe($expectedStatusCode)
        ->and((string) $response->getBody())->toBeEmpty();
})->with(function () {
    yield 'general error' => [
        'exception' => new RuntimeException('☠️'),
        'expectedStatusCode' => StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE,
    ];
    yield 'timeout' => [
        'exception' => new TimeoutException(0),
        'expectedStatusCode' => StatusCodeInterface::STATUS_GATEWAY_TIMEOUT,
    ];
});
