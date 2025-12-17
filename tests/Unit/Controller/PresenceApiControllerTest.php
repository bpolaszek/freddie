<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Controller;

use Freddie\Controller\PresenceApiController;
use Freddie\Hub\Hub;
use Freddie\Subscription\Subscriber;
use Freddie\Tests\Mock\PHPTransport;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

use function Freddie\Tests\createJWT;
use function Freddie\Tests\createSfRequest;
use function Freddie\urn;
use function ob_get_clean;
use function ob_start;

it('returns subscriptions', function () {
    $hub = new Hub(new PHPTransport(), ['subscriptions' => true]);
    $controller = new PresenceApiController($hub);

    // When
    $john = new Subscriber(['foo', 'bar'], id: new Ulid('01JWR1XYQQHJG20AWR5GGRB5WZ'));
    $bob = new Subscriber(['foo'], id: new Ulid('01JWR1XYQR7BCESHHGHSF2J8BZ'));
    $hub->subscribe($john);
    $hub->subscribe($bob);
    $request = createSfRequest('GET', '/.well-known/mercure/subscriptions', [
        'Authorization' => 'Bearer ' . createJWT([
                'mercure' => [
                    'subscribe' => [
                        '/.well-known/mercure/subscriptions',
                    ],
                ],
            ]),
    ]);
    $response = $controller->getSubscriptions($request);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->headers->get('content-type'))->toBe('application/ld+json');

    ob_start() && $response->send();
    $output = ob_get_clean();

    $json = json_decode($output, true);
    expect($json)->toBe([
        '@context' => 'https://mercure.rocks/',
        'id' => '/.well-known/mercure/subscriptions',
        'type' => 'Subscriptions',
        'lastEventID' => urn($hub->getLastEventId()),
        'subscriptions' =>
            [
                [
                    'id' => '/.well-known/mercure/subscriptions/foo/urn%3Auuid%3A0197301e-faf7-8ca0-202b-982c2185979f',
                    'type' => 'Subscription',
                    'subscriber' => urn($john->id),
                    'topic' => 'foo',
                    'active' => true,
                ],
                [
                    'id' => '/.well-known/mercure/subscriptions/bar/urn%3Auuid%3A0197301e-faf7-8ca0-202b-982c2185979f',
                    'type' => 'Subscription',
                    'subscriber' => urn($john->id),
                    'topic' => 'bar',
                    'active' => true,
                ],
                [
                    'id' => '/.well-known/mercure/subscriptions/foo/urn%3Auuid%3A0197301e-faf8-3ad8-ecc6-308e5e29217f',
                    'type' => 'Subscription',
                    'subscriber' => urn($bob->id),
                    'topic' => 'foo',
                    'active' => true,
                ],
            ],
    ]);
});

it('returns subscriptions for a specific topic', function () {
    $hub = new Hub(new PHPTransport(), ['subscriptions' => true]);
    $controller = new PresenceApiController($hub);

    // When
    $john = new Subscriber(['foo', 'bar'], id: new Ulid('01JWR1XYQQHJG20AWR5GGRB5WZ'));
    $bob = new Subscriber(['foo'], id: new Ulid('01JWR1XYQR7BCESHHGHSF2J8BZ'));
    $hub->subscribe($john);
    $hub->subscribe($bob);
    $request = createSfRequest('GET', '/.well-known/mercure/subscriptions/foo', [
        'Authorization' => 'Bearer ' . createJWT([
                'mercure' => [
                    'subscribe' => [
                        '*',
                    ],
                ],
            ]),
    ]);
    $response = $controller->getSubscriptions($request, 'foo');
    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->headers->get('content-type'))->toBe('application/ld+json');

    ob_start() && $response->send();
    $output = ob_get_clean();

    $json = json_decode($output, true);
    expect($json)->toBe([
        '@context' => 'https://mercure.rocks/',
        'id' => '/.well-known/mercure/subscriptions/foo',
        'type' => 'Subscriptions',
        'lastEventID' => urn($hub->getLastEventId()),
        'subscriptions' =>
            [
                [
                    'id' => '/.well-known/mercure/subscriptions/foo/urn%3Auuid%3A0197301e-faf7-8ca0-202b-982c2185979f',
                    'type' => 'Subscription',
                    'subscriber' => urn($john->id),
                    'topic' => 'foo',
                    'active' => true,
                ],
                [
                    'id' => '/.well-known/mercure/subscriptions/foo/urn%3Auuid%3A0197301e-faf8-3ad8-ecc6-308e5e29217f',
                    'type' => 'Subscription',
                    'subscriber' => urn($bob->id),
                    'topic' => 'foo',
                    'active' => true,
                ],
            ],
    ]);
});

it('returns a 400 error when subscriptions are not enabled', function () {
    $hub = new Hub(new PHPTransport(), ['subscriptions' => false]);
    $controller = new PresenceApiController($hub);

    // When
    $request = createSfRequest('GET', '/.well-known/mercure/subscriptions', [
        'Authorization' => 'Bearer ' . createJWT([
                'mercure' => [
                    'subscribe' => [
                        '/.well-known/mercure/subscriptions',
                    ],
                ],
            ]),
    ]);

    expect(fn () => $controller->getSubscriptions($request))->toThrow(
        BadRequestHttpException::class,
        "Subscriptions are not enabled on this hub.",
    );
});

it('returns a 403 error when a JWT is not provided', function () {
    $hub = new Hub(new PHPTransport(), ['subscriptions' => true]);
    $controller = new PresenceApiController($hub);

    // When
    $request = createSfRequest('GET', '/.well-known/mercure/subscriptions');

    expect(fn () => $controller->getSubscriptions($request))->toThrow(
        AccessDeniedHttpException::class,
        'You must provide a valid JWT to access this endpoint.',
    );
});

it('returns a 403 error when JWT `subscribe` claims does not contain the appropriate topic', function (string $jwt) {
    $hub = new Hub(new PHPTransport(), ['subscriptions' => true]);
    $controller = new PresenceApiController($hub);

    // When
    $request = createSfRequest('GET', '/.well-known/mercure/subscriptions', [
        'Authorization' => 'Bearer ' . $jwt,
    ]);

    expect(fn () => $controller->getSubscriptions($request))->toThrow(AccessDeniedHttpException::class);
})->with([
    'no subscribe claim' => createJWT(),
    'empty subscribe claim' => createJWT(['mercure' => ['subscribe' => []]]),
    'subscribe claim with wrong topic' => createJWT(['mercure' => ['subscribe' => ['wrong-topic']]]),
]);

it('returns a specific subscription', function () {
    $hub = new Hub(new PHPTransport(), ['subscriptions' => true]);
    $controller = new PresenceApiController($hub);

    // When
    $john = new Subscriber(['foo', 'bar'], id: new Ulid('01JWR1XYQQHJG20AWR5GGRB5WZ'));
    $hub->subscribe($john);
    $url = '/.well-known/mercure/subscriptions/foo/urn%3Auuid%3A0197301e-faf7-8ca0-202b-982c2185979f';
    $request = createSfRequest('GET', $url, [
        'Authorization' => 'Bearer ' . createJWT([
                'mercure' => [
                    'subscribe' => [
                        '*',
                    ],
                ],
            ]),
    ]);
    $response = $controller->getSubscription($request);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->headers->get('content-type'))->toBe('application/ld+json');

    ob_start() && $response->send();
    $output = ob_get_clean();

    $json = json_decode($output, true);
    expect($json)->toBe([
        '@context' => 'https://mercure.rocks/',
        'id' => $url,
        'type' => 'Subscription',
        'subscriber' => urn($john->id),
        'topic' => 'foo',
        'active' => true,
        'lastEventID' => urn($hub->getLastEventId()),
    ]);
});


it('returns a 404 error if not found', function () {
    $hub = new Hub(new PHPTransport(), ['subscriptions' => true]);
    $controller = new PresenceApiController($hub);

    // When
    $john = new Subscriber(['foo', 'bar'], id: new Ulid());
    $hub->subscribe($john);
    $url = '/.well-known/mercure/subscriptions/foo/urn%3Auuid%3A0197301e-faf7-8ca0-202b-982c2185979f';
    $request = createSfRequest('GET', $url, [
        'Authorization' => 'Bearer ' . createJWT([
                'mercure' => [
                    'subscribe' => [
                        '*',
                    ],
                ],
            ]),
    ]);

    expect(fn () => $controller->getSubscription($request))->toThrow(NotFoundHttpException::class);
});
