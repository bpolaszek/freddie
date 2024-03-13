<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use Clue\React\Redis\RedisClient;
use Freddie\Hub\Transport\Redis\RedisTransport;
use Freddie\Hub\Transport\Redis\RedisTransportFactory;

it('supports Redis DSNs', function (string $dsn, bool $expected) {
    $factory = new RedisTransportFactory();
    expect($factory->supports($dsn))->toBe($expected);
})->with(function () {
    yield ['redis://localhost', true];
    yield ['rediss://some.secure.place.com', true];
    yield ['notredis://shrug', false];
});

it('instantiates a Redis transport', function (string $dsn, RedisTransport $expected) {
    $factory = new RedisTransportFactory();
    expect($factory->create($dsn))->toEqual($expected);
})->with(function () {
    yield ['redis://localhost?foo=bar', new RedisTransport(
        new RedisClient('redis://localhost?foo=bar'),
        new RedisClient('redis://localhost?foo=bar'),
    )];
    yield ['redis://localhost?size=1000&trimInterval=2.5', new RedisTransport(
        new RedisClient('redis://localhost?size=1000&trimInterval=2.5'),
        new RedisClient('redis://localhost?size=1000&trimInterval=2.5'),
        options: ['size' => 1000, 'trimInterval' => 2.5],
    )];
});

it('instantiates 2 different clients', function () {
    $factory = new RedisTransportFactory();
    /** @var RedisTransport $transport */
    $transport = $factory->create('redis://localhost?size=1000');
    expect($transport->redis)->toBeInstanceOf(RedisClient::class);
    expect($transport->subscriber)->toBeInstanceOf(RedisClient::class);
    expect($transport->redis)->not()->toBe($transport->subscriber);
});
