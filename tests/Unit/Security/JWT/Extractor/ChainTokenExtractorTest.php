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
    $validToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30._esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';

    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => 'mercureAuthorization=' . $validToken,
        ]),
        'expected' => $validToken,
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure?authorization=' . $validToken),
        'expected' => $validToken,
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => 'mercureAuthorization=' . $validToken,
            'Authorization' => 'Bearer foo',
        ]),
        'expected' => $validToken,
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => 'mercureAuthorization=foo',
            'Authorization' => 'Bearer ' . $validToken,
        ]),
        'expected' => $validToken,
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer ' . $validToken,
        ]),
        'expected' => $validToken,
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => 'mercureAuthorization=foobar',
        ]),
        'expected' => null,
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => 'mercureAuthorization=foo',
            'Authorization' => 'Bearer bar',
        ]),
        'expected' => null,
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer foobar',
        ]),
        'expected' => null,
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure?authorization=foobar'),
        'expected' => null,
    ];
    yield [
        'request' => new ServerRequest('GET', '/.well-known/mercure'),
        'expected' => null,
    ];
});
