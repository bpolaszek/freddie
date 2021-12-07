<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;

it('extracts token either from cookies or authorization header', function (
    ServerRequestInterface $request,
    ?string $expected
) {
    $extractor = new ChainTokenExtractor();
    expect($extractor->extract($request))->toBe($expected);
})->with(function () {
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => 'mercureAuthorization=foobar',
        ]),
        'expected' => 'foobar',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => 'mercureAuthorization=foo',
            'Authorization' => 'Bearer bar',
        ]),
        'expected' => 'foo',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer foobar',
        ]),
        'expected' => 'foobar',
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure'),
        'expected' => null,
    ];
});
