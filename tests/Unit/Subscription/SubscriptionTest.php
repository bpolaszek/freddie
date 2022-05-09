<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Subscription;

use Freddie\Subscription\Subscriber;
use Freddie\Subscription\Subscription;
use Symfony\Component\Uid\Ulid;

use function json_decode;
use function json_encode;

it('properly serializes', function (Subscription $subscription, array $expected) {
    $serialized = json_encode($subscription);
    expect(json_decode($serialized, true))->toBe($expected);
})->with(function () {
    $subscriberId = new Ulid();
    yield [
        'subscription' => (new Subscriber(['foo'], id: $subscriberId))->subscriptions[0],
        'expected' => [
            'id' => "/.well-known/mercure/subscriptions/foo/{$subscriberId}",
            'type' => 'Subscription',
            'subscriber' => (string) $subscriberId,
            'topic' => 'foo',
            'active' => true,
        ],
    ];
    yield [
        'subscription' => (new Subscriber(['/foo/{*}'], id: $subscriberId))->subscriptions[0],
        'expected' => [
            'id' => "/.well-known/mercure/subscriptions/%2Ffoo%2F%7B%2A%7D/{$subscriberId}",
            'type' => 'Subscription',
            'subscriber' => (string) $subscriberId,
            'topic' => '/foo/{*}',
            'active' => true,
        ],
    ];
    yield [
        'subscription' => (new Subscriber(['/foo/{*}'], ['greet' => 'Hello 👋'], $subscriberId))->subscriptions[0],
        'expected' => [
            'id' => "/.well-known/mercure/subscriptions/%2Ffoo%2F%7B%2A%7D/{$subscriberId}",
            'type' => 'Subscription',
            'subscriber' => (string) $subscriberId,
            'topic' => '/foo/{*}',
            'active' => true,
            'payload' => ['greet' => 'Hello 👋'],
        ],
    ];
    yield [
        'subscription' => (new Subscriber(['/foo/{*}'], 'Hello 👋', $subscriberId, false))->subscriptions[0],
        'expected' => [
            'id' => "/.well-known/mercure/subscriptions/%2Ffoo%2F%7B%2A%7D/{$subscriberId}",
            'type' => 'Subscription',
            'subscriber' => (string) $subscriberId,
            'topic' => '/foo/{*}',
            'active' => false,
            'payload' => 'Hello 👋',
        ],
    ];
});
