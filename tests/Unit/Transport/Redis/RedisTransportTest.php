<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Transport\Redis;

use BenTools\ReflectionPlus\Reflection;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Subscription\Subscriber;
use Freddie\Tests\Mock\RedisStub;
use Freddie\Transport\Redis\LazyRedis;
use Freddie\Transport\Redis\RedisTransport;
use Mockery;
use Mockery\Mock;
use Nyholm\Dsn\Configuration\Dsn;
use Nyholm\Dsn\DsnParser;
use Symfony\Component\Uid\Ulid;

use function expect;
use function Freddie\urn;
use function json_encode;
use function urlencode;

it('creates a RedisTransport with default options', function () {
    // Given
    // @phpstan-ignore-next-line varTag.unresolvableType
    /** @var RedisStub&Mock $redis */
    $redis = Mockery::spy(new RedisStub());

    // When
    $parsedDsn = DsnParser::parse('redis://p4ssw0rd@localhost');
    $transport = new RedisTransport(LazyRedis::factory($parsedDsn, $redis));

    // Then
    $redis->shouldNotHaveReceived('connect');
    $redis->shouldNotHaveReceived('auth');

    // When
    $transport->push(new Update('foo', new Message(data: 'bar')));

    // Then
    $redis->shouldHaveReceived('connect');
    $redis->shouldHaveReceived('auth');
});

it('creates a RedisTransport with custom options', function () {
    // Given
    // @phpstan-ignore-next-line varTag.unresolvableType
    /** @var RedisStub&Mock $redis */
    $redis = Mockery::spy(new RedisStub());

    // When
    $parsedDsn = DsnParser::parse('redis://p4ssw0rd@localhost?size=5000&trimInterval=30&stream=mercure&maxBufferedItemsPerStream=100&blockDurationMs=1000&sleepDurationMs=100&maxIterations=100000'); // @codingStandardsIgnoreLine
    $transport = new RedisTransport(LazyRedis::factory($parsedDsn, $redis))->withOptionsFromDsn($parsedDsn);
    $resolvedOptions = Reflection::property($transport, 'options')->getValue($transport);

    // Then
    expect($resolvedOptions)->toHaveCount(6)
        ->and($resolvedOptions['stream'])->toEqual('mercure')
        ->and($resolvedOptions['size'])->toEqual(5000)
        ->and($resolvedOptions['maxBufferedItemsPerStream'])->toEqual(100)
        ->and($resolvedOptions['blockDurationMs'])->toEqual(1000)
        ->and($resolvedOptions['sleepDurationMs'])->toEqual(100)
        ->and($resolvedOptions['maxIterations'])->toEqual(100000);

    // When
    $transport->push(new Update('foo', new Message(data: 'bar')));

    // Then
    $redis->shouldHaveReceived('connect');
    $redis->shouldHaveReceived('auth');
});

it('publishes an update to Redis', function () {
    // Given
    // @phpstan-ignore-next-line varTag.unresolvableType
    /** @var RedisStub&Mock $redis */
    $redis = Mockery::spy(new RedisStub());
    $transport = new RedisTransport(LazyRedis::factory(redis: $redis));

    // When
    $id = new Ulid('01JWB2RD507NWBR79PWB56VCFC');
    $transport->push(new Update('foo', new Message(id: $id, data: 'bar')));

    // Then
    $expectedPayload = json_encode([
        'topics' => ['foo'],
        'message' => [
            'id' => [
                'dateTime' => [
                    'timezone' => [
                        'name' => '+00:00',
                        'transitions' => false,
                        'location' => false,
                    ],
                    'offset' => 0,
                    'timestamp' => 1748423685,
                    'microsecond' => 280000,
                ]
            ],
            'data' => 'bar',
            'private' => false,
            'event' => null,
            'retry' => null,
        ],
    ]);
    $redis->shouldHaveReceived('xadd', [
        'freddie',
        '1748423685280-0',
        [
            '01JWB2RD507NWBR79PWB56VCFC',
            $expectedPayload,
        ],
        1000,
        true,
    ]);
});

it('subscribes to updates from Redis', function () {
    // Given
    // @phpstan-ignore-next-line varTag.unresolvableType
    /** @var RedisStub&Mock $redis */
    $redis = Mockery::spy(new RedisStub());
    $options = ['maxIterations' => 2, 'sleepDurationMs' => 0];
    $transport = new RedisTransport(LazyRedis::factory(redis: $redis), options: $options);

    // When
    $id = new Ulid('01JWB2RD507NWBR79PWB56VCFC');
    $subscriber = new Subscriber(['foo']);
    $transport->registerSubscriber($subscriber);
    $transport->push(new Update('foo', new Message(id: $id, data: 'bar')));
    foreach ($transport->listen('earliest') as $update) {
        // Do nothing, just iterate through the updates
    };
    $transport->unregisterSubscriber($subscriber);

    // Then
    $redis->shouldHaveReceived('sAdd', [
        'freddie:subscriber-index',
        (string) $subscriber->id,
    ]);
    $redis->shouldHaveReceived('hMset', [
        'freddie:subscriber:' . $subscriber->id,
        $subscriber->jsonSerialize(),
    ]);
    $redis->shouldHaveReceived('xread', [
        ['freddie' => 0],
        1000,
        5000,
    ]);
    $redis->shouldHaveReceived('srem', [
        'freddie:subscriber-index',
        (string) $subscriber->id,
    ]);
    $redis->shouldHaveReceived('del', [
        'freddie:subscriber:' . $subscriber->id,
    ]);
});

it('returns the last event ID', function () {
    // Given
    // @phpstan-ignore-next-line varTag.unresolvableType
    /** @var RedisStub&Mock $redis */
    $redis = Mockery::spy(new RedisStub());
    $transport = new RedisTransport(LazyRedis::factory(redis: $redis));

    // When
    $lastEventId = $transport->getLastEventId();

    // Then
    expect($lastEventId)->toBeNull();

    // When
    $id = new Ulid();
    $transport->push(new Update('foo', new Message(id: $id, data: 'bar')));

    // Then
    $redis->shouldHaveReceived('set', [
        'freddie:lastEventID',
        (string) $id,
    ]);

    // When
    $lastEventId = $transport->getLastEventId();

    // Then
    $redis->shouldHaveReceived('get', [
        'freddie:lastEventID',
    ]);

    expect($lastEventId)->toEqual($id);
});

it('lists subscriptions', function () {
    // Given
    // @phpstan-ignore-next-line varTag.unresolvableType
    /** @var RedisStub&Mock $redis */
    $redis = Mockery::spy(new RedisStub());
    $transport = new RedisTransport(LazyRedis::factory(redis: $redis));

    // When
    $subscriber1 = new Subscriber(['foo'], id: new Ulid('01JWB2RD507NWBR79PWB56VCFC'));
    $subscriber2 = new Subscriber(['bar'], id: new Ulid('01JWB2RD507NWBR79PWB56VCFD'));
    $transport->registerSubscriber($subscriber1);
    $transport->registerSubscriber($subscriber2);

    // Then
    $redis->shouldHaveReceived('sAdd', [
        'freddie:subscriber-index',
        (string) $subscriber1->id,
    ]);
    $redis->shouldHaveReceived('hMset', [
        'freddie:subscriber:' . $subscriber1->id,
        $subscriber1->jsonSerialize(),
    ]);
    $redis->shouldHaveReceived('sAdd', [
        'freddie:subscriber-index',
        (string) $subscriber2->id,
    ]);
    $redis->shouldHaveReceived('hMset', [
        'freddie:subscriber:' . $subscriber2->id,
        $subscriber2->jsonSerialize(),
    ]);

    // When
    $subscribers = iterator_to_array($transport->getSubscriptions(null));

    // Then
    expect($subscribers)->toHaveCount(2);

    // When
    $subscribers = iterator_to_array($transport->getSubscriptions('bar'));

    // Then
    expect($subscribers)->toHaveCount(1);
});

it('fetches a subscription', function () {
    // Given
    // @phpstan-ignore-next-line varTag.unresolvableType
    /** @var RedisStub&Mock $redis */
    $redis = new RedisStub();
    $transport = new RedisTransport(LazyRedis::factory(redis: $redis));
    $subscriber = new Subscriber(['foo'], id: new Ulid('01JWB2RD507NWBR79PWB56VCFC'));
    $transport->registerSubscriber($subscriber);

    // When
    $subscriptionIri = '/.well-known/mercure/subscriptions/foo/' . urlencode(urn($subscriber->id));
    $subscription = $transport->getSubscription($subscriptionIri);

    // Then
    expect($subscription)->toEqual($subscriber->subscriptions[0]);

    // When
    $subscriptionIri = '/.well-known/mercure/subscriptions/whatever/' . urlencode(urn(new Ulid()));
    $subscription = $transport->getSubscription($subscriptionIri);

    // Then
    expect($subscription)->toBeNull();
});
