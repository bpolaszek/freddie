<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use Freddie\Hub\Transport\Redis\RedisTransport;
use Freddie\Hub\Transport\Redis\RedisTransportFactory;
use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;

it('supports Redis DSNs', function (string $dsn, bool $expected) {
    $factory = new RedisTransportFactory();
    expect($factory->supports($dsn))->toBe($expected);
})->with(function () {
    yield ['redis://localhost?pingInterval=0.0', true];
    yield ['rediss://some.secure.place.com', true];
    yield ['notredis://shrug', false];
});

it('instantiates a Redis transport', function (string $dsn, RedisTransport $expected) {
    $factory = new RedisTransportFactory();
    expect($factory->create($dsn))->toEqual($expected);
})->with(function () {
    $redisFactory = new Factory();
    yield ['redis://localhost?foo=bar&pingInterval=0.0', new RedisTransport(
        $redisFactory->createLazyClient('redis://localhost?foo=bar&pingInterval=0.0'),
        $redisFactory->createLazyClient('redis://localhost?foo=bar&pingInterval=0.0'),
        options: ['pingInterval' => 0.0],
    )];
    yield ['redis://localhost?size=1000&trimInterval=2.5&pingInterval=0.0', new RedisTransport(
        $redisFactory->createLazyClient('redis://localhost?size=1000&trimInterval=2.5&pingInterval=0.0'),
        $redisFactory->createLazyClient('redis://localhost?size=1000&trimInterval=2.5&pingInterval=0.0'),
        options: ['size' => 1000, 'trimInterval' => 2.5, 'pingInterval' => 0.0],
    )];
})->skip('Pest object comparison does not work as expected');

it('instantiates 2 different clients', function () {
    $factory = new RedisTransportFactory();
    /** @var RedisTransport $transport */
    $transport = $factory->create('redis://localhost?size=1000&pingInterval=0.0');
    expect($transport->redis)->toBeInstanceOf(Client::class);
    expect($transport->subscriber)->toBeInstanceOf(Client::class);
    expect($transport->redis)->not()->toBe($transport->subscriber);
});
