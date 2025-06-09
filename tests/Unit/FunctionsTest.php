<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit;

use Symfony\Component\Uid\Ulid;

use function Freddie\fromUrn;
use function Freddie\is_truthy;
use function Freddie\nullify;
use function Freddie\urn;

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

it('returns an ULID as an URN', function () {
    $ulid = new Ulid('01JWBP6W10ZCR91MSMGSF8FS1Y');
    expect(urn($ulid))->toBe('urn:uuid:01971763-7020-fb30-90d3-34865e87e43e');
});

it('instantiates an ULID from an URN', function () {
    $urn = 'urn:uuid:01971763-7020-fb30-90d3-34865e87e43e';
    $ulid = fromUrn($urn);
    expect($ulid)->toBeInstanceOf(Ulid::class)
        ->and($ulid->compare(new Ulid('01JWBP6W10ZCR91MSMGSF8FS1Y')))->toBe(0);
});

it('throws an exception if the URN is invalid', function () {
    $urn = 'invalid-urn-format';
    expect(fn () => fromUrn($urn))->toThrow(\InvalidArgumentException::class, 'Invalid URN format for Ulid');
});
