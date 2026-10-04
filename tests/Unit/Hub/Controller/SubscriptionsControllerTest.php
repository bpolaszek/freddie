<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Controller;

use FrameworkX\App;
use Freddie\Hub\Controller\SubscribeController;
use Freddie\Hub\Controller\SubscriptionsController;
use Freddie\Hub\Hub;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Hub\Transport\PHP\PHPTransport;
use Freddie\Message\Message;
use Freddie\Message\Update;
use React\Http\Message\ServerRequest;

use function Freddie\Tests\subscriptions_app;
use function Freddie\Tests\subscriptions_request;
use function Freddie\Tests\access_token;
use function Freddie\Tests\access_token_policy;
use function Freddie\Tests\detail;
use function Freddie\Tests\handle;
use function Freddie\Tests\jwt_config;
use function Freddie\Tests\run_loop;
use function Freddie\Tests\with_token;

it('lists active subscriptions', function () {
    [$app, $hub, $subscribe] = subscriptions_app();
    $subscribe(
        with_token(
            new ServerRequest('GET', '/.well-known/mercure?match=/books/1&match_urlpattern=' . urlencode('/books/:id')),
            access_token([detail(['subscribe'], ['/books/1'], ['user' => 'alice'])]),
        ),
        new ThroughStreamStub(),
    );
    $subscribe(new ServerRequest('GET', '/.well-known/mercure?match=/authors/1'), new ThroughStreamStub());
    [$alice, $anonymous] = $hub->getSubscribers();
    run_loop(); // flushes the subscription events into the history
    $hub->publish(new Update(['/foo'], new Message(id: 'last')));

    // When
    $response = handle($app, subscriptions_request('/.well-known/mercure/subscriptions'));

    // Then
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($response->getHeaderLine('ETag'))->toMatch('/^"[0-9a-f]{32}"$/')
        ->and($response->getHeaderLine('Link'))->toBe(
            '</.well-known/mercure>; rel="mercure"; last-event-id="last"; type="mercure"; '
            . 'content-type="application/json"'
        )
        ->and(json_decode((string) $response->getBody(), true))->toBe([
            'id' => '/.well-known/mercure/subscriptions',
            'type' => 'subscriptions',
            'subscriptions' => [
                $alice->subscriptions[0]->toArray(true),
                $alice->subscriptions[1]->toArray(true),
                $anonymous->subscriptions[0]->toArray(true),
            ],
        ])
        ->and($alice->subscriptions[0]->toArray(true)['payload'])->toBe(['user' => 'alice'])
        ->and($alice->subscriptions[1]->toArray(true))->not->toHaveKey('payload');
});

it('filters subscriptions', function (string $path, int $expectedCount) {
    [$app, , $subscribe] = subscriptions_app();
    $subscribe(new ServerRequest('GET', '/.well-known/mercure?match=/books/1&match=/books/2'), new ThroughStreamStub());
    $subscribe(new ServerRequest('GET', '/.well-known/mercure?match_urlpattern=/books/1'), new ThroughStreamStub());

    $response = handle($app, subscriptions_request($path));

    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode((string) $response->getBody(), true)['subscriptions'])->toHaveCount($expectedCount);
})->with([
    'by type' => ['/.well-known/mercure/subscriptions/exact', 2],
    'by type and match' => ['/.well-known/mercure/subscriptions/exact/' . rawurlencode('/books/1'), 1],
    'by other type' => ['/.well-known/mercure/subscriptions/urlpattern/' . rawurlencode('/books/1'), 1],
    'no match' => ['/.well-known/mercure/subscriptions/exact/' . rawurlencode('/books/3'), 0],
]);

it('gets a single subscription', function () {
    [$app, $hub, $subscribe] = subscriptions_app();
    $subscribe(new ServerRequest('GET', '/.well-known/mercure?match=/books/1'), new ThroughStreamStub());
    $subscription = $hub->getSubscribers()[0]->subscriptions[0];

    $response = handle($app, subscriptions_request($subscription->getId()));

    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode((string) $response->getBody(), true))->toBe($subscription->toArray(true))
        ->and($response->getHeaderLine('ETag'))->toMatch('/^"[0-9a-f]{32}"$/');
});

it('answers 404 for an unknown subscription', function () {
    [$app, , $subscribe] = subscriptions_app();
    $subscribe(new ServerRequest('GET', '/.well-known/mercure?match=/books/1'), new ThroughStreamStub());

    $response = handle($app, subscriptions_request(
        '/.well-known/mercure/subscriptions/exact/' . rawurlencode('/books/1') . '/' . rawurlencode('urn:uuid:unknown'),
    ));

    expect($response->getStatusCode())->toBe(404);
});

it('answers 304 until the subscriptions change', function () {
    // The default transport keeps no history: the cursor alone cannot tell the subscriptions changed.
    [$app, , $subscribe] = subscriptions_app(historySize: 0);
    $etag = handle($app, subscriptions_request('/.well-known/mercure/subscriptions'))->getHeaderLine('ETag');
    $conditional = fn () => handle(
        $app,
        subscriptions_request('/.well-known/mercure/subscriptions', headers: ['If-None-Match' => $etag]),
    );

    // When nothing changed
    $notModified = $conditional();

    // When someone subscribes
    $subscribe(new ServerRequest('GET', '/.well-known/mercure?match=/books/1'), new ThroughStreamStub());
    $modified = $conditional();

    // Then
    expect($notModified->getStatusCode())->toBe(304)
        ->and((string) $notModified->getBody())->toBe('')
        ->and($notModified->getHeaderLine('ETag'))->toBe($etag)
        ->and($modified->getStatusCode())->toBe(200)
        ->and($modified->getHeaderLine('ETag'))->not->toBe($etag);
});

it('rejects unsupported matcher types', function () {
    [$app] = subscriptions_app();

    $response = handle($app, subscriptions_request('/.well-known/mercure/subscriptions/regexp'));

    expect($response->getStatusCode())->toBe(400);
});

it('requires a subscribe grant on the subscription URL', function (
    ?string $jwt,
    int $expectedStatus,
    string $expectedChallenge,
) {
    [$app] = subscriptions_app();
    $request = new ServerRequest(
        'GET',
        'http://localhost/.well-known/mercure/subscriptions',
        null === $jwt ? [] : ['Authorization' => "Bearer $jwt"],
    );

    $response = handle($app, $request);

    expect($response->getStatusCode())->toBe($expectedStatus)
        ->and($response->getHeaderLine('WWW-Authenticate'))->toBe($expectedChallenge);
})->with(function () {
    yield 'anonymous' => [null, 401, 'Bearer'];
    yield 'no grant' => [access_token([detail(['subscribe'], ['/foo'])]), 403, 'Bearer error="insufficient_scope"'];
    yield 'publish grant' => [access_token([detail(['publish'], ['*'])]), 403, 'Bearer error="insufficient_scope"'];
});

it('is disabled by default', function () {
    [$app] = subscriptions_app(enabled: false);

    $response = handle($app, subscriptions_request('/.well-known/mercure/subscriptions'));

    expect($response->getStatusCode())->toBe(404);
});
