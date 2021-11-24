<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Tests\Unit;

use Psr\Http\Message\ServerRequestInterface;
use RingCentral\Psr7\ServerRequest;

use function BenTools\MercurePHP\extract_last_event_id;
use function BenTools\MercurePHP\is_truthy;
use function BenTools\MercurePHP\nullify;

it('is truthy', function (mixed $input, bool $expected) {
    expect(is_truthy($input))->toBe($expected);
})
->with(function () {
    yield ['yes', true];
    yield ['YES', true];
    yield ['on', true];
    yield ['ON', true];
    yield ['y', true];
    yield ['Y', true];
    yield ['true', true];
    yield ['TRUE', true];
    yield ['1', true];
    yield [1, true];
    yield [true, true];
    yield ['no', false];
    yield ['NO', false];
    yield ['off', false];
    yield ['OFF', false];
    yield ['n', false];
    yield ['N', false];
    yield ['false', false];
    yield ['FALSE', false];
    yield ['0', false];
    yield [0, false];
    yield [false, false];
    yield ['', false];
    yield ['whatever', false];
});

it('nullifies stuff', function (mixed $input, ?string $cast, mixed $expected) {
    expect(nullify($input, $cast))->toBe($expected);
})->with(function () {
    $obj = new \stdClass();
    yield [null, null, null];
    yield [null, 'string', null];
    yield ['null', null, 'null'];
    yield ['', null, null];
    yield [' ', null, null];
    yield [' ', 'string', null];
    yield ['0', 'int', 0];
    yield [0, null, 0];
    yield [$obj, null, $obj];
});

it('extracts Last-Event-ID from request', function (ServerRequestInterface $request, ?string $expected) {
    expect(extract_last_event_id($request))->toBe($expected);
})->with(function () {
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', ['Last-Event-ID' => 'foo']),
        'expected' => 'foo',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', ['Last-Event-Id' => 'foo']),
        'expected' => 'foo',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', ['last-event-id' => 'foo']),
        'expected' => 'foo',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', ['LAST-EVENT-ID' => 'foo']),
        'expected' => 'foo',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure?Last-Event-ID=foo'),
        'expected' => 'foo',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure?Last-Event-Id=foo'),
        'expected' => 'foo',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure?last-event-id=foo'),
        'expected' => 'foo',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure?LAST-EVENT-ID=foo'),
        'expected' => 'foo',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure'),
        'expected' => null,
    ];
});
